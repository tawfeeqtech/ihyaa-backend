<?php

namespace App\Policies;

use App\Models\Interest;
use App\Models\User;

/**
 * T046 — سياسات طلبات الاهتمام (US-044 · contract §5/§6/§7).
 *
 * accept/reject: مالك المشروع فقط (+ المشرف وفق دستور §V).
 * cancel:        المستثمر المرسل فقط (+ المشرف).
 * view:          أحد الطرفين (المرسل أو مالك المشروع) + المشرف — §4.
 */
class InterestPolicy
{
    public function accept(User $user, Interest $interest): bool
    {
        return $user->isAdmin() || $interest->project?->isOwner($user) === true;
    }

    public function reject(User $user, Interest $interest): bool
    {
        return $user->isAdmin() || $interest->project?->isOwner($user) === true;
    }

    public function cancel(User $user, Interest $interest): bool
    {
        return $user->isAdmin() || (int) $interest->investor_id === (int) $user->id;
    }

    public function view(User $user, Interest $interest): bool
    {
        return $interest->isParty($user);
    }
}
