<?php

namespace App\Services\Agreement;

use App\Models\Agreement;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * T053 — حراسة الوصول إلى مستند الاتفاق (US-045 · دستور §V).
 *
 * الأطراف فقط (صاحب الفكرة أو المستثمر) + المشرف (دستور §I — المشرف يطّلع
 * للدعم/التدقيق). أي طرف ثالث: 403 + سجل أمني Log::warning('agreement_access_denied').
 */
class AgreementAccessGuard
{
    public function authorize(User $user, Agreement $agreement): bool
    {
        return $user->isAdmin()
            || (int) $agreement->idea_owner_id === (int) $user->id
            || (int) $agreement->investor_id === (int) $user->id;
    }

    /** يرمي 403 + سجل أمني عند عدم التصريح. */
    public function assertAccess(User $user, Agreement $agreement): void
    {
        if (! $this->authorize($user, $agreement)) {
            Log::warning('agreement_access_denied', [
                'user_id' => $user->id,
                'agreement_id' => $agreement->id,
                'interest_id' => $agreement->interest_id,
                'ip' => request()->ip(),
            ]);

            throw new AccessDeniedHttpException(__('agreement.access_denied'));
        }
    }
}
