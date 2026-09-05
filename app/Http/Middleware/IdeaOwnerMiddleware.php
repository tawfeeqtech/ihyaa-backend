<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;

class IdeaOwnerMiddleware extends AbstractRoleMiddleware
{
    protected function role(): UserRole
    {
        return UserRole::IDEA_OWNER;
    }
}
