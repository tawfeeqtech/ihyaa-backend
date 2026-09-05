<?php

namespace App\Models;

use App\Enums\AnalysisType;
use App\Enums\ArtifactStatus;
use App\Enums\ModelUsed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مخرجات وكيل تحليل المشروع — data-model.md §5 (SRS-DB-09).
 * artifact_data: نصوص وقوالب فقط (SRS-AI-M03). لا updated_at في المخطط.
 * status/error_message/language — EPIC-15 (T103/T104/T122) للمسار غير المتزامن.
 */
class AiAgentArtifact extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'project_id',
        'analysis_type',
        'artifact_data',
        'version',
        'model_used',
        'status',
        'language',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'analysis_type' => AnalysisType::class,
            'artifact_data' => 'array',
            'version' => 'integer',
            'model_used' => ModelUsed::class,
            'status' => ArtifactStatus::class,
            'language' => 'string',
            'error_message' => 'string',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** أحدث إصدار لكل (project, type) — T115: GET /projects/{project}/ai-analysis */
    public function scopeLatestPerType(Builder $query): Builder
    {
        return $query
            ->whereIn('id', function ($q) {
                $q->selectRaw('MAX(id)')
                    ->from('ai_agent_artifacts')
                    ->groupBy('project_id', 'analysis_type');
            });
    }
}
