<?php

namespace App\Http\Controllers\Api;

use App\Services\Project\TagSuggestionService;
use App\Models\Tag;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * اقتراحات الوسوم — SRS-API-49 · RL-PUB-01 (30/دقيقة · IP).
 * حتى 10 اقتراحات — تُستخرج من وسوم المشاريع المنشورة (Cache 10 دقائق).
 *
 * T160: المنطق في TagSuggestionService · T166: الرد data بدل suggestions (contract).
 */
class TagController
{
    use ApiResponse;

    public function __construct(private readonly TagSuggestionService $tags)
    {
    }

    public function suggestions(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:50'],
        ]);

        $term = strtolower((string) $request->input('q', ''));

        // T166: الرد data مباشرةً (contract §tags) — success() يغلّف الحمولة داخل data تلقائياً
        return $this->success($this->tags->suggestions($term));
    }

    /** GET /api/tags — قائمة الوسوم المستقلة النشطة. */
    public function index(): JsonResponse
    {
        return $this->success(Tag::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get());
    }

    /** POST /api/tags — المدير أو صاحب الفكرة. */
    public function store(Request $request): JsonResponse
    {
        if (! $this->canCreate($request)) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'slug' => ['nullable', 'string', 'max:50', 'unique:tags,slug'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $data['name'] = $this->normalize($data['name']);
        $data['slug'] ??= Tag::slugify($data['name']);

        if (Tag::where('slug', $data['slug'])->exists()) {
            return $this->conflict('TAG_EXISTS', 'The tag already exists.');
        }

        $tag = Tag::create($data);

        return $this->created($tag, 'Tag created.');
    }

    /** PUT /api/tags/{tag} — المدير فقط. */
    public function update(Request $request, Tag $tag): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:50'],
            'slug' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('tags', 'slug')->ignore($tag->id)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['name'])) {
            $data['name'] = $this->normalize($data['name']);
        }

        $tag->update($data);

        return $this->success($tag->fresh(), 'Tag updated.');
    }

    /** DELETE /api/tags/{tag} — المدير فقط. */
    public function destroy(Request $request, Tag $tag): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return $this->forbidden();
        }

        $tag->delete();

        return $this->noContent('Tag deleted.');
    }

    private function canCreate(Request $request): bool
    {
        return $request->user()?->isAdmin() || $request->user()?->isIdeaOwner();
    }

    private function normalize(string $tag): string
    {
        return trim($tag);
    }
}
