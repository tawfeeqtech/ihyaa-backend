<?php

namespace App\Services\AI;

use App\Enums\AnalysisType;
use App\Enums\ArtifactStatus;
use App\Models\AiAgentArtifact;
use Illuminate\Support\Facades\DB;

/**
 * T114 — ترقيم إصدارات مخرجات وكيل AI (US-080 سيناريو التحديث · T120 "تحديث التحليل").
 *
 * version = MAX(version)+1 لنفس (project_id, analysis_type) داخل معاملة مع
 * lockForUpdate (يمنع السباقات بين تحديثين متزامنين). لكل (project, type) يبدأ من 1.
 */
class ArtifactVersioner
{
    public function nextVersion(int $projectId, string|AnalysisType $type): int
    {
        $type = $type instanceof AnalysisType ? $type->value : $type;

        return (int) DB::transaction(function () use ($projectId, $type) {
            $latest = AiAgentArtifact::query()
                ->where('project_id', $projectId)
                ->where('analysis_type', $type)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            return $latest ? $latest->version + 1 : 1;
        });
    }

    /** إنشاء سجل "processing" برقم الإصدار المحسوب — T103 */
    public function createProcessing(int $projectId, string|AnalysisType $type, string $language = 'ar'): AiAgentArtifact
    {
        $type = $type instanceof AnalysisType ? $type : AnalysisType::from($type);

        return AiAgentArtifact::create([
            'project_id' => $projectId,
            'analysis_type' => $type,
            'artifact_data' => [],
            'version' => $this->nextVersion($projectId, $type),
            'status' => ArtifactStatus::PROCESSING,
            'language' => $language,
            'error_message' => null,
        ]);
    }
}
