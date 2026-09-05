<?php

namespace App\Jobs;

use App\Enums\ArtifactStatus;
use App\Models\AiAgentArtifact;
use App\Models\Evaluation;
use App\Models\Notification;
use App\Models\Project;
use App\Services\AI\CompetitiveReportGenerator;
use App\Services\AI\CompetitorSelector;
use App\Services\AI\SwotAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * T104 — معالجة تحليل وكيل AI (US-080..084 · SRS-API-42) — غير متزامنة على Horizon.
 *
 * القناة `ai-analysis` · tries=1 (الفشل يُسجَّل على artifact ولا يُعاد) · timeout=200s.
 * lockOwner: معرّف قفل Cache::lock الذي حازه الـ Controller — يُحرَّر في finally
 * (يرفع "لا يزال قيد المعالجة" 409 بعد اكتمال/فشل هذه المعالجة).
 * عند النجاح: إشعار analysis_completed (غير حرج — لا بث Reverb).
 */
class AnalyzeProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 200;

    public function __construct(
        public int $artifactId,
        public string $lockOwner = '',
    ) {
        $this->onQueue('ai-analysis');
    }

    public function handle(
        CompetitorSelector $selector,
        SwotAnalyzer $swot,
        CompetitiveReportGenerator $competitive,
    ): void {
        $artifact = AiAgentArtifact::find($this->artifactId);

        if (! $artifact) {
            return;
        }

        $project = $artifact->project;
        $type = $artifact->analysis_type->value;
        $language = (string) ($artifact->language ?? 'ar');

        $lock = $this->restoreLock($project, $type);

        try {
            $data = match ($type) {
                'comparison' => $selector->select($project),
                'swot' => $swot->analyze($project, $this->latestEvaluation($project), $language),
                'competitive' => $competitive->generate($project, $this->latestEvaluation($project), null, $language),
                default => throw new RuntimeException("Unsupported analysis type: {$type}"),
            };

            $modelUsed = $data['_model_used'] ?? null;
            unset($data['_model_used']);

            $artifact->forceFill([
                'artifact_data' => $data,
                'status' => ArtifactStatus::COMPLETED,
                'model_used' => $modelUsed,
                'error_message' => null,
            ])->save();

            Notification::pushNotification(
                (int) $project->user_id,
                'analysis_completed',
                __('ai_agent.analysis_completed_title'),
                null,
                [
                    'project_id' => $project->id,
                    'project_title' => (string) $project->title,
                    'analysis_type' => $type,
                    'version' => $artifact->version,
                    'url' => '/projects/'.$project->id.'/analysis?type='.$type,
                ],
                isCritical: false,
            );
        } catch (\Throwable $e) {
            $artifact->forceFill([
                'status' => ArtifactStatus::FAILED,
                'error_message' => $e->getMessage(),
            ])->save();
        } finally {
            $lock?->release();
        }
    }

    /** إعادة إنشاء القفل بنفس المالك حتى يتحرر قفل الـ Controller في finally */
    private function restoreLock(Project $project, string $type): ?\Illuminate\Contracts\Cache\Lock
    {
        $key = 'ai-analysis:'.$project->id.':'.$type;

        if ($this->lockOwner !== '') {
            return Cache::restoreLock($key, $this->lockOwner);
        }

        return Cache::lock($key, 600);
    }

    private function latestEvaluation(Project $project): ?Evaluation
    {
        return $project->latestCompletedEvaluation();
    }
}
