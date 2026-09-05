<?php

namespace App\Models;

use App\Enums\ModelUsed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سجل طلبات مزودي AI للمعايرة — data-model.md §4 (FR-207 / SRS-AI-M04).
 * قيد صارم: لا حقول نصية لمحتوى الطلب/الاستجابة — معرفات ومقاييس فقط.
 * لا updated_at في المخطط.
 */
class AiRequestLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'evaluation_id',
        'project_id',
        'dimension',
        'provider',
        'model',
        'attempt',
        'success',
        'latency_ms',
        'prompt_tokens',
        'completion_tokens',
        'failure_reason',
        'fallback_reason',
        'consensus_round',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => ModelUsed::class,
            'attempt' => 'integer',
            'success' => 'boolean',
            'latency_ms' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'consensus_round' => 'boolean',
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
