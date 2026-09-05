<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;

class AdminMiddleware extends AbstractRoleMiddleware
{
    protected function role(): UserRole
    {
        return UserRole::ADMIN;   // يُنشأ عبر seeder فقط — لا تسجيل عام
    }
}
