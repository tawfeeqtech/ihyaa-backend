<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مستند الاتفاق الثابت (SRS-F08 · UC-07 · FR-310) — data-model.md §2.2.
 *
 * سجل 1:1 مع interest. الأسماء (idea_owner_name / investor_name) نسخة وقت
 * القبول — لا يتغير نصّ PDF الاتفاق إذا عدّل المستخدم اسمه لاحقاً (research.md).
 *
 * الوصول: الأطراف فقط + الأدمن (AgreementAccessGuard / InterestPolicy) — V.
 */
class Agreement extends Model
{
    protected $fillable = [
        'interest_id',
        'idea_owner_id',
        'investor_id',
        'project_id',
        'pdf_path',
        'idea_owner_name',
        'investor_name',
    ];

    public function interest(): BelongsTo
    {
        return $this->belongsTo(Interest::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function ideaOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'idea_owner_id');
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investor_id');
    }
}
