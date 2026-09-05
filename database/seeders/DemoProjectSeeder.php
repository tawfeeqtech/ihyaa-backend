<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoProjectSeeder extends Seeder
{
    /**
     * بيانات تجريبية للتطوير المحلي والاختبار فقط.
     *
     * لا يُستدعى في بيئة الإنتاج — DatabaseSeeder يحمي الاستدعاء
     * بشرط: if (! app()->environment('production')).
     */
    public function run(): void
    {
        $owner = User::updateOrCreate(
            ['email' => 'demo-owner@ihyaa.test'],
            [
                'name' => 'Demo Idea Owner',
                'password' => 'password',   // cast 'hashed' يشفّرها تلقائياً
                'role' => UserRole::IDEA_OWNER,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $categoryIds = Category::query()->pluck('id')->all();

        if (empty($categoryIds)) {
            $this->command?->warn('لا توجد تصنيفات — شغّل CategorySeeder أولاً. تم تخطي المشاريع التجريبية.');

            return;
        }

        Project::factory()
            ->count(12)
            ->published()
            ->create([
                'user_id' => $owner->id,
                'category_id' => fn () => $categoryIds[array_rand($categoryIds)],
            ]);
    }
}
