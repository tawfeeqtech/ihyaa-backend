<?php

namespace App\Policies;

use App\Models\AiAgentArtifact;
use App\Models\User;

/**
 * T116 — سياسة الوصول لمخرجات وكيل AI (US-080..084).
 * المالك فقط (+ المشرف وفق الدستور §V) — غير المالك 403.
 */
class AiAgentArtifactPolicy
{
    public function view(User $user, AiAgentArtifact $artifact): bool
    {
        return $user->isAdmin() || $artifact->project?->isOwner($user) === true;
    }
}
