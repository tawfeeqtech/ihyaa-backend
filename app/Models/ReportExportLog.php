<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تدقيق طلبات تصدير تقارير PDF — data-model.md §6 (US-028-S5).
 * لا updated_at في المخطط.
 */
class ReportExportLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'evaluation_id',
        'user_id',
        'access_level',
        'language',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_level' => 'string',
            'language' => 'string',
            'status' => 'string',
        ];
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class, 'evaluation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
