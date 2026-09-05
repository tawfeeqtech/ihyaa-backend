<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * لقطة مدخلات التقييم — data-model.md §3.
 * بلا بيانات حساسة (مبدأ V / SRS-TEST-AI-11)؛ لا updated_at في المخطط.
 */
class EvaluationInputSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'evaluation_id',
        'project_id',
        'description',
        'github_url',
        'files_meta',
        'video_meta',
        'team_meta',
        'business_info',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'files_meta' => 'array',
            'video_meta' => 'array',
            'team_meta' => 'array',
            'business_info' => 'array',
        ];
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class, 'evaluation_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
