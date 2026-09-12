<?php

namespace App\Services\External;

use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class EvaluationWebhookService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function apply(array $payload): Evaluation
    {
        $eventId = (string) ($payload['event_id'] ?? '');
        $callbackId = (string) ($payload['callback_id'] ?? '');
        $pythonId = (string) ($payload['python_evaluation_id'] ?? '');
        $projectId = (int) ($payload['project_id'] ?? 0);
        $status = (string) ($payload['status'] ?? '');

        if ($eventId === '' || $callbackId === '' || $pythonId === '' || $projectId <= 0) {
            throw new InvalidArgumentException('Webhook identity fields are required.');
        }

        $evaluation = Evaluation::where('webhook_event_id', $eventId)->first();

        if ($evaluation !== null) {
            return $evaluation;
        }

        if (! preg_match('/^laravel-evaluation-(\d+)$/', $callbackId, $matches)) {
            throw new InvalidArgumentException('Invalid callback_id.');
        }

        $evaluation = Evaluation::find((int) $matches[1]);

        if ($evaluation === null
            || $evaluation->external_idempotency_key === null
            || (int) $evaluation->project_id !== $projectId) {
            throw new InvalidArgumentException('Evaluation does not match webhook project.');
        }

        if ($evaluation->python_evaluation_id !== null && $evaluation->python_evaluation_id !== $pythonId) {
            throw new InvalidArgumentException('Python evaluation id does not match.');
        }

        $nextStatus = EvaluationStatus::tryFrom($status);

        if ($nextStatus === null || ! in_array($nextStatus, [EvaluationStatus::COMPLETED, EvaluationStatus::PARTIAL, EvaluationStatus::FAILED], true)) {
            throw new InvalidArgumentException('Invalid terminal evaluation status.');
        }

        return DB::transaction(function () use ($evaluation, $payload, $eventId, $pythonId, $nextStatus) {
            $report = is_array($payload['report'] ?? null) ? $payload['report'] : null;
            $error = $nextStatus === EvaluationStatus::FAILED
                ? [[
                    'type' => (string) ($payload['error_code'] ?? 'external_evaluation_failed'),
                    'message' => (string) ($payload['message'] ?? 'Python evaluation failed.'),
                    'timestamp' => now()->toISOString(),
                ]]
                : null;

            $evaluation->fill([
                'status' => $nextStatus,
                'python_evaluation_id' => $pythonId,
                'webhook_event_id' => $eventId,
                'overall_score' => $payload['overall_score'] ?? ($report['overall_score'] ?? null),
                'confidence_score' => $payload['confidence_score'] ?? ($report['confidence_score'] ?? null),
                'result' => $report,
                'error_log' => $error,
                'completed_at' => now(),
                'processing_time_ms' => $payload['processing_time_ms'] ?? null,
            ])->save();

            if (in_array($nextStatus, [EvaluationStatus::COMPLETED, EvaluationStatus::PARTIAL], true)) {
                $evaluation->project()->update([
                    'ai_score' => $evaluation->overall_score,
                    'last_evaluation_at' => now(),
                ]);
            }

            return $evaluation->fresh();
        });
    }
}