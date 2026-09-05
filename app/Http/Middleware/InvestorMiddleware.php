<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;

class InvestorMiddleware extends AbstractRoleMiddleware
{
    protected function role(): UserRole
    {
        return UserRole::INVESTOR;
    }
}
