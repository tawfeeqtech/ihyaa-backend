<?php

namespace App\Services;

use App\Enums\InterestStatus;
use App\Enums\UserRole;

/**
 * T024 — آلة الحالات الخالصة لطلبات الاهتمام (US-041..046).
 *
 * كائن بلا قاعدة بيانات — قابل للاختبار 100% (InterestStatusMachineTest).
 * يحدد انتقالات الحالة الصالحة لكل دور فاعل:
 *
 *   pending
 *     ├──→ accepted                  (صاحب الفكرة / مشرف)
 *     ├──→ accepted_pending_document (صاحب الفكرة — فشل PDF مؤقت — FR-310)
 *     ├──→ rejected                  (صاحب الفكرة / مشرف)
 *     └──→ cancelled                 (المستثمر المرسل / مشرف)
 *   accepted
 *     └──→ cancelled                 (المستثمر المرسل / مشرف — UC-07 E2)
 *   accepted_pending_document
 *     ├──→ accepted                  (النظام — نجاح/فشل نهائي لإعادة المحاولة)
 *     └──→ cancelled                 (المستثمر المرسل / مشرف)
 *   rejected  ──→ (لا انتقال — حالة نهائية)
 *   cancelled ──→ (لا انتقال — حالة نهائية)
 *
 * قاعدة "لا إعادة معالجة من accepted": أي انتقال من حالة إلى نفسها مرفوض
 * (من ضمنها accepted → accepted) — لا إعادة قبول/معالجة لطلب مقبول.
 *
 * ملاحظة الأدوار: يُسمح للمشرف بأي انتقال يسمح به الطرفان (دستور §V).
 */
class InterestStatusMachine
{
    public const ROLE_OWNER = UserRole::IDEA_OWNER->value;
    public const ROLE_INVESTOR = UserRole::INVESTOR->value;
    public const ROLE_ADMIN = UserRole::ADMIN->value;
    public const ROLE_SYSTEM = 'system';

    /**
     * هل الانتقال من حالة إلى أخرى صالح للدور الفاعل؟
     */
    public function canTransition(InterestStatus $from, InterestStatus $to, string $actorRole): bool
    {
        // الحالات النهائية: لا انتقال منها إطلاقاً (US-044 السيناريو 4/5).
        if (in_array($from, [InterestStatus::REJECTED, InterestStatus::CANCELLED], true)) {
            return false;
        }

        // لا إعادة معالجة من نفس الحالة (من ضمنها accepted → accepted).
        if ($from === $to) {
            return false;
        }

        return match ($from) {
            InterestStatus::PENDING => match ($to) {
                InterestStatus::ACCEPTED => $this->isOwner($actorRole),
                InterestStatus::ACCEPTED_PENDING_DOCUMENT => $this->isOwner($actorRole),
                InterestStatus::REJECTED => $this->isOwner($actorRole),
                InterestStatus::CANCELLED => $this->isInvestor($actorRole),
                default => false,
            },

            InterestStatus::ACCEPTED => match ($to) {
                InterestStatus::CANCELLED => $this->isInvestor($actorRole),
                default => false,
            },

            InterestStatus::ACCEPTED_PENDING_DOCUMENT => match ($to) {
                InterestStatus::ACCEPTED => $this->isSystem($actorRole),
                InterestStatus::CANCELLED => $this->isInvestor($actorRole),
                default => false,
            },

            default => false,
        };
    }

    private function isOwner(string $role): bool
    {
        return in_array($role, [self::ROLE_OWNER, self::ROLE_ADMIN], true);
    }

    private function isInvestor(string $role): bool
    {
        return in_array($role, [self::ROLE_INVESTOR, self::ROLE_ADMIN], true);
    }

    private function isSystem(string $role): bool
    {
        return $role === self::ROLE_SYSTEM;
    }
}
