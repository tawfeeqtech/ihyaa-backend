<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * 15 تصنيفاً (SRS-F02-01) — enums.md §1.12 + السياحة (سوق مستهدف في المنطقة).
     * تصنيفات مسطحة — قائمة منسدلة واحدة.
     */
    public function run(): void
    {
        $categories = [
            ['slug' => 'fintech', 'name_ar' => 'التقنية المالية', 'name_en' => 'Fintech', 'icon' => 'bank'],
            ['slug' => 'healthtech', 'name_ar' => 'التقنية الصحية', 'name_en' => 'Healthtech', 'icon' => 'first-aid'],
            ['slug' => 'edtech', 'name_ar' => 'التقنية التعليمية', 'name_en' => 'Edtech', 'icon' => 'graduation-cap'],
            ['slug' => 'ecommerce', 'name_ar' => 'التجارة الإلكترونية', 'name_en' => 'E-commerce', 'icon' => 'shopping-cart'],
            ['slug' => 'saas', 'name_ar' => 'البرمجيات كخدمة', 'name_en' => 'SaaS', 'icon' => 'cloud'],
            ['slug' => 'ai', 'name_ar' => 'الذكاء الاصطناعي', 'name_en' => 'Artificial Intelligence', 'icon' => 'robot'],
            ['slug' => 'agritech', 'name_ar' => 'التقنية الزراعية', 'name_en' => 'Agritech', 'icon' => 'plant'],
            ['slug' => 'logistics', 'name_ar' => 'اللوجستيات', 'name_en' => 'Logistics', 'icon' => 'truck'],
            ['slug' => 'real_estate', 'name_ar' => 'العقارات', 'name_en' => 'Real Estate', 'icon' => 'building'],
            ['slug' => 'energy', 'name_ar' => 'الطاقة', 'name_en' => 'Energy', 'icon' => 'lightning'],
            ['slug' => 'gaming', 'name_ar' => 'الألعاب', 'name_en' => 'Gaming', 'icon' => 'game-controller'],
            ['slug' => 'social', 'name_ar' => 'الشبكات الاجتماعية', 'name_en' => 'Social', 'icon' => 'users'],
            ['slug' => 'marketplace', 'name_ar' => 'الأسواق الرقمية', 'name_en' => 'Marketplace', 'icon' => 'storefront'],
            ['slug' => 'tourism', 'name_ar' => 'السياحة', 'name_en' => 'Tourism', 'icon' => 'airplane'],
            ['slug' => 'other', 'name_ar' => 'أخرى', 'name_en' => 'Other', 'icon' => 'circles-three'],
        ];

        foreach ($categories as $index => $category) {
            Category::updateOrCreate(
                ['slug' => $category['slug']],
                [...$category, 'sort_order' => $index + 1, 'is_active' => true]
            );
        }
    }
}
