<?php

namespace App\Services;

use App\Enums\InterestStatus;
use App\Exceptions\Interest\DuplicateInterestException;
use App\Models\Interest;

/**
 * T025 — الفحص التطبيقي لمنع الطلب النشط المكرر (US-043).
 *
 * يوجد طلب فعّال (pending / accepted / accepted_pending_document) لنفس
 * (investor_id, project_id)؟ طبقة الدفاع الأولى؛ الطبقة الثانية هي الفهرس
 * الفريد المشروط active_dup_key على قاعدة البيانات (T041/T042 — يلتقط السباق).
 */
class InterestDuplicateGuard
{
    public function exists(int $projectId, int $investorId): bool
    {
        return Interest::query()
            ->where('project_id', $projectId)
            ->where('investor_id', $investorId)
            ->whereIn('status', [
                InterestStatus::PENDING,
                InterestStatus::ACCEPTED,
                InterestStatus::ACCEPTED_PENDING_DOCUMENT,
            ])
            ->exists();
    }

    /**
     * يرمي DuplicateInterestException عند وجود طلب نشط — وإلا يمرّ بصمت.
     */
    public function assertNoActive(int $projectId, int $investorId): void
    {
        if ($this->exists($projectId, $investorId)) {
            throw new DuplicateInterestException();
        }
    }
}
