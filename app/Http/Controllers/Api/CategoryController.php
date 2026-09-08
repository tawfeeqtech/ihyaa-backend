<?php

namespace App\Http\Controllers\Api;

use App\Models\Category;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    /** POST /api/categories — المدير أو صاحب الفكرة. */
    public function store(Request $request): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120', 'unique:categories,slug'],
            'icon' => ['nullable', 'string', 'max:120'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $data['slug'] ??= Category::slugify($data['name_en']);

        if (Category::where('slug', $data['slug'])->exists()) {
            return $this->conflict('CATEGORY_SLUG_EXISTS', 'The category slug already exists.');
        }

        $category = Category::create($data);

        return $this->created($category, 'Category created.');
    }

    /** PUT /api/categories/{category} — المدير فقط. */
    public function update(Request $request, Category $category): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'name_ar' => ['sometimes', 'required', 'string', 'max:120'],
            'name_en' => ['sometimes', 'required', 'string', 'max:120'],
            'slug' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('categories', 'slug')->ignore($category->id)],
            'icon' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $category->update($data);

        return $this->success($category->fresh(), 'Category updated.');
    }

    /** DELETE /api/categories/{category} — المدير فقط. */
    public function destroy(Request $request, Category $category): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return $this->forbidden();
        }

        if ($category->projects()->exists()) {
            return $this->conflict('CATEGORY_IN_USE', 'The category is assigned to one or more projects.');
        }

        $category->delete();

        return $this->noContent('Category deleted.');
    }

    private function canManage(Request $request): bool
    {
        return $request->user()?->isAdmin() || $request->user()?->isIdeaOwner();
    }
}
