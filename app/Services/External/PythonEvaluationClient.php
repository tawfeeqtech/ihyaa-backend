<?php

namespace App\Services\External;

use App\Models\Evaluation;
use App\Models\Project;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class PythonEvaluationClient
{
    /**
     * @param  array<string, mixed>  $projectPayload
     * @return array<string, mixed>
     */
    public function start(Project $project, Evaluation $evaluation, array $projectPayload, string $idempotencyKey): array
    {
        $url = rtrim((string) config('services.python_evaluation.url'), '/');

        if ($url === '') {
            throw new RuntimeException('Python evaluation URL is not configured.');
        }

        $response = $this->request()->post($url.'/api/v1/evaluations', [
            'project_id' => $project->id,
            'callback_id' => 'laravel-evaluation-'.$evaluation->id,
            'idempotency_key' => $idempotencyKey,
            'callback_url' => (string) config('services.python_evaluation.webhook_url'),
            'project' => $projectPayload,
        ]);

        if ($response->failed()) {
            $detail = $response->json();
            $detail = is_array($detail) ? json_encode($detail, JSON_UNESCAPED_UNICODE) : $response->body();

            throw new RuntimeException(
                'Python evaluation service returned HTTP '.$response->status().': '.$detail
            );
        }

        $payload = $response->json();
        $pythonEvaluationId = $payload['evaluation_id'] ?? null;

        if (! is_string($pythonEvaluationId) || $pythonEvaluationId === '') {
            throw new RuntimeException('Python evaluation response did not include evaluation_id.');
        }

        return $payload;
    }

    private function request(): PendingRequest
    {
        return Http::asJson()
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(10)
            ->withHeaders([
                'X-API-Key' => (string) config('services.python_evaluation.api_key'),
            ]);
    }
}