<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /** 3 صفوف فقط — admin لا يُنشأ بالتسجيل العام (SRS §1.2) */
    public function run(): void
    {
        $roles = [
            ['name' => 'idea_owner', 'display_name' => 'صاحب فكرة', 'description' => 'يرفع المشاريع ويدير طلبات الاهتمام'],
            ['name' => 'investor', 'display_name' => 'مستثمر', 'description' => 'يكتشف المشاريع ويبدي الاهتمام ويحفظها'],
            ['name' => 'admin', 'display_name' => 'مشرف', 'description' => 'لوحة التحليلات والتصدير — seeder فقط'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['name' => $role['name']], $role);
        }
    }
}
