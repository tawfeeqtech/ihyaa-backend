<?php

namespace App\Services;

use App\Models\User;

/**
 * اكتمال الملف الإلزامي حسب الدور (UC-06 A1 — contract §1).
 *
 * المستثمر لا يستطيع إرسال طلب اهتمام قبل إكمال حقوله الإلزامية:
 *   investment_focus (إلزامي) + preferred_sectors (إلزامي — ProfileResource §2).
 * عند النقص → ProfileIncompleteException مع redirect: /profile/edit.
 *
 * ملاحظة: متعمَّد عدم الربط مع جدول user_profiles — حقول المستثمر الأساسية
 * ما زالت على جدول users (Sprint 1). أي نقل مستقبلي يعدّل هذا الملف فقط.
 */
class ProfileCompletenessService
{
    /** الحقول الإلزامية لمستثمر يمكنه إبداء الاهتمام (UC-06 A1). */
    public const INVESTOR_REQUIRED = ['investment_focus', 'preferred_sectors'];

    public function isInvestorComplete(User $investor): bool
    {
        return filled($investor->investment_focus)
            && filled($investor->preferred_sectors);
    }

    /**
     * يرمي ProfileIncompleteException إن كان المستثمر ناقص الملف.
     */
    public function assertInvestorComplete(User $investor): void
    {
        if (! $this->isInvestorComplete($investor)) {
            throw new \App\Exceptions\Interest\ProfileIncompleteException();
        }
    }
}
