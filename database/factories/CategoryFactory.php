<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * يولد slug قانوني فريداً من القائمة الـ 15 (عبر unique) حتى لا يتلوث
     * DB التطوير بسلَغات عشوائية غير معروفة للواجهة، مع إضافة لاحقة رقمية
     * عند تجاوز الـ 15 لضمان التفرد في الاختبارات. مصدر الحقيقة هو CategorySeeder.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $canonical = [
            'fintech' => 'التقنية المالية', 'healthtech' => 'التقنية الصحية',
            'edtech' => 'التقنية التعليمية', 'ecommerce' => 'التجارة الإلكترونية',
            'saas' => 'البرمجيات كخدمة', 'ai' => 'الذكاء الاصطناعي',
            'agritech' => 'التقنية الزراعية', 'logistics' => 'اللوجستيات',
            'real_estate' => 'العقارات', 'energy' => 'الطاقة',
            'gaming' => 'الألعاب', 'social' => 'الشبكات الاجتماعية',
            'marketplace' => 'الأسواق الرقمية', 'tourism' => 'السياحة',
            'other' => 'أخرى',
        ];

        $slugs = array_keys($canonical);

        // تسلسل ثابت لضمان التفرد داخل الاختبار: أول 15 = القانونية، ثم لاحقة رقمية.
        static $seq = 0;
        $seq++;
        $base = $slugs[($seq - 1) % count($slugs)];
        $slug = $seq <= count($slugs) ? $base : $base.'-'.$seq;

        return [
            'name_ar' => $canonical[$base],
            'name_en' => ucfirst(str_replace('_', ' ', $base)),
            'slug' => $slug,
            'icon' => fake()->randomElement(['bank', 'robot', 'cloud', 'truck']),
            'sort_order' => fake()->numberBetween(1, 20),
            'is_active' => true,
        ];
    }
}
