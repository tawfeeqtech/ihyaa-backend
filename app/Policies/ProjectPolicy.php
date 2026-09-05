<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * T162 — سياسات المشروع (SRS-API-15..18, 35..37).
 *
 * تحل محل استدعاءات isOwner() المبعثرة في الـ controllers
 * (ProjectController / TrashController / FileController).
 * تُسجَّل في AuthServiceProvider — تُستدعى عبر Gate::can / FormRequest::authorize.
 */
class ProjectPolicy
{
    /** تعديل المشروع (SRS-API-16) — المالك فقط. */
    public function update(User $user, Project $project): bool
    {
        return $project->isOwner($user);
    }

    /** حذف ناعم (SRS-API-17) — المالك فقط. */
    public function destroy(User $user, Project $project): bool
    {
        return $project->isOwner($user);
    }

    /** رفع/حذف ملفات المشروع (SRS-API-18) — المالك فقط. */
    public function files(User $user, Project $project): bool
    {
        return $project->isOwner($user);
    }

    /** استرجاع من سلة المهملات (SRS-API-36) — المالك فقط. */
    public function restore(User $user, Project $project): bool
    {
        return $project->isOwner($user);
    }

    /** حذف نهائي (SRS-API-37) — المالك فقط. */
    public function forceDelete(User $user, Project $project): bool
    {
        return $project->isOwner($user);
    }
}
