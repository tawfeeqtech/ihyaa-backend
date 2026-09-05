<?php

namespace App\Services\Agreements;

use App\Enums\InterestStatus;
use App\Models\Interest;

/**
 * مستودع الاتفاقات — مصدر "الاتفاق النشط" (contracts/report-api.md §3 · US-029).
 *
 * تنفيذ مرحلي في Sprint 2 يقرأ `interests.status = 'accepted'` (حالة القبول تعني
 * اتِّفاقاً ضمنياً في MVP). يُستبدل بجدول الاتفاقات في Sprint 3 عبر نفس الواجهة —
 * لا تغيير على المتصلين (DisclosureService / EvaluationPolicy).
 */
class AgreementRepository
{
    /**
     * هل لدى المستثمر اتفاق نشط مع المشروع؟
     *
     * @param  int  $projectId  معرف المشروع
     * @param  int  $investorId  معرف المستثمر
     */
    public function hasActiveAgreement(int $projectId, int $investorId): bool
    {
        return Interest::query()
            ->where('project_id', $projectId)
            ->where('investor_id', $investorId)
            ->where('status', InterestStatus::ACCEPTED)
            ->exists();
    }
}
