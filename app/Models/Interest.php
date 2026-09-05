<?php

namespace App\Models;

use App\Enums\InterestStatus;
use App\Enums\InterestType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Interest extends Model
{
    protected $fillable = [
        'project_id',
        'investor_id',
        'interest_type',
        'message',
        'status',
        'rejection_reason',
        'agreement_pdf_path',
        'agreement_id',          // T056 — رابط مستند الاتفاق (يُملأ عند نجاح توليد PDF)
        'accepted_at',
        'rejected_at',
        'cancelled_at',
    ];

    /**
     * active_dup_key عمود مولّد (VIRTUAL) تديره قاعدة البيانات — T041/T043.
     * لا يُكتب من التطبيق؛ يُقيَّم إلى investor_id للحالات النشطة فقط و NULL
     * لغيرها، مع فهرس فريد (project_id, active_dup_key) يمنع التكرار النشط.
     */

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InterestStatus::class,
            'interest_type' => InterestType::class,
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investor_id');
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /** هل المستخدم طرف في الطلب؟ (المستثمر أو مالك المشروع) — T053/InterestPolicy. */
    public function isParty(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->isAdmin()
            || (int) $this->investor_id === (int) $user->id
            || $this->project?->isOwner($user);
    }

    // ——————————————————————— آلة الحالات (SRS-F08) ———————————————————————

    /** قبول الطلب: إنشاء PDF الاتفاق + كشف البريد المتبادل (UC-07) */
    public function accept(): void
    {
        $this->forceFill([
            'status' => InterestStatus::ACCEPTED,
            'accepted_at' => now(),
        ])->save();
    }

    /** رفض الطلب مع سبب اختياري */
    public function reject(?string $reason = null): void
    {
        $this->forceFill([
            'status' => InterestStatus::REJECTED,
            'rejection_reason' => $reason,
            'rejected_at' => now(),
        ])->save();
    }

    /** إلغاء (من المستثمر — pending أو accepted مع حذف ملف PDF — UC-07 E2) */
    public function cancel(): void
    {
        $this->forceFill([
            'status' => InterestStatus::CANCELLED,
            'cancelled_at' => now(),
        ])->save();
    }

    public function toApiArray(bool $includeContacts = false): array
    {
        $data = [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'investor' => [
                'id' => $this->investor?->id,
                'name' => $this->investor?->name,
                'avatar_url' => $this->investor?->avatar_path
                    ? asset('storage/'.$this->investor->avatar_path)
                    : null,
            ],
            'interest_type' => $this->interest_type->value,
            'message' => $this->message,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'rejection_reason' => $this->rejection_reason,
            'agreement_url' => $this->agreement_pdf_path
                ? asset('storage/'.$this->agreement_pdf_path)
                : null,
            'accepted_at' => $this->accepted_at?->toISOString(),
            'rejected_at' => $this->rejected_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];

        // كشف البريد فقط بعد القبول (UC-07)
        if ($includeContacts && $this->status === InterestStatus::ACCEPTED) {
            $data['investor']['email'] = $this->investor?->email;
            $data['owner_email'] = $this->project?->owner?->email;
        }

        return $data;
    }
}
