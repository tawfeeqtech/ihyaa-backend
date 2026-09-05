<?php

namespace App\Policies;

use App\Models\Agreement;
use App\Models\User;
use App\Services\Agreement\AgreementAccessGuard;

/**
 * T053 — سياسة عرض/تحميل مستند الاتفاق (US-045 · دستور §V).
 * الطرفان فقط + المشرف (delegates إلى AgreementAccessGuard).
 */
class AgreementPolicy
{
    public function view(User $user, Agreement $agreement): bool
    {
        return app(AgreementAccessGuard::class)->authorize($user, $agreement);
    }
}
