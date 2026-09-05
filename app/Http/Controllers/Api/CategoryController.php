<?php

namespace App\Http\Controllers\Api;

use App\Models\Category;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * التصنيفات — SRS-F02-01 · SRS-API-49 (L2 عام).
 * مصدر القطاعات للواجهة (قائمة منسدلة إنشاء مشروع + فلاتر المعرض).
 */
class CategoryController
{
    use ApiResponse;

    /** GET /api/categories — قائمة التصنيفات النشطة مرتّبة (RL-PUB-01). */
    public function index(): JsonResponse
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Category $c) => [
                'id' => $c->id,
                'slug' => $c->slug,
                'name_ar' => $c->name_ar,
                'name_en' => $c->name_en,
                'icon' => $c->icon,
            ])
            ->values();

        return $this->success($categories);
    }
}
