<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminSeeder extends Seeder
{
    /**
     * حساب المشرف (admin@ihyaa.test) بكلمة مرور عشوائية.
     *
     * يُطبع الرمز في console بصيغة:  Admin password: [random]
     * يتطلب أن يكون RoleSeeder قد ركض أولاً (لمزامنة pivot role_user).
     * الدور admin (enum) لا يُنشأ بالتسجيل العام (SRS §1.2).
     */
    public function run(): void
    {
        $password = Str::random(24);

        $admin = User::updateOrCreate(
            ['email' => 'admin@ihyaa.test'],
            [
                'name' => 'Ihyaa Admin',
                'password' => $password,      // cast 'hashed' يشفّرها تلقائياً — لا Hash::make يدوي
                'role' => UserRole::ADMIN,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // مزامنة pivot role_user (البحث بالاسم — لا IDs صلبة)
        $roleModel = Role::where('name', UserRole::ADMIN->value)->first();

        if ($roleModel) {
            $admin->roles()->syncWithoutDetaching([$roleModel->id]);
        }

        $this->command?->info("Admin password: {$password}");
    }
}
