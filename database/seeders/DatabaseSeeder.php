<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed بيانات المنصة.
     *
     * الترتيب مهم:
     *  1) الأدوار (تُستخدم في التسجيل ومزامنة pivot admin)
     *  2) التصنيفات (تتطلبها DemoProjectSeeder)
     *  3) المشرف (يتطلب الأدوار)
     *  4) البيانات التجريبية — غير الإنتاج فقط
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            CategorySeeder::class,
            AdminSeeder::class,
        ]);

        // بيانات تجريبية — لا تُنشأ في بيئة الإنتاج
        if (! app()->environment('production')) {
            $this->call([
                DemoProjectSeeder::class,
            ]);
        }
    }
}
