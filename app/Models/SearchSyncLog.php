<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * أخطاء مزامنة فهرس Meilisearch — data-model.md §6 (US-034-S5).
 * متعدد الأشكال (indexable_type/indexable_id). لا updated_at في المخطط.
 */
class SearchSyncLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'indexable_type',
        'indexable_id',
        'action',
        'status',
        'error',
        'attempts',
        'last_attempt_at',
        'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => 'string',
            'status' => 'string',
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function indexable(): MorphTo
    {
        return $this->morphTo();
    }
}
