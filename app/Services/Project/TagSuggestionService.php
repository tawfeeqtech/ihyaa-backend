<?php

namespace App\Services\Project;

use App\Models\Project;
use Illuminate\Support\Facades\Cache;

/**
 * T160 — اقتراحات الوسوم (SRS-API-49).
 *
 * حتى 10 اقتراحات — تُستخرج من وسوم المشاريع المنشورة (Cache 10 دقائق).
 * الفلترة بالبادئة (q) + ترتيب حسب الشعبية (view_count ثم تكرار الوسم).
 */
class TagSuggestionService
{
    private const CACHE_TTL = 600;   // 10 دقائق

    private const LIMIT = 10;        // حد الاقتراحات (SRS-API-49)

    /** @return string[] الاقتراحات المرتّبة حسب الشعبية (حد 10). */
    public function suggestions(string $term): array
    {
        $tags = Cache::remember('tags:suggestions', self::CACHE_TTL, fn () => $this->collectTags());

        return collect($tags)
            ->filter(fn ($count, $tag) => $term === '' || str_contains(strtolower($tag), $term))
            ->sortDesc()
            ->keys()
            ->take(self::LIMIT)
            ->values()
            ->all();
    }

    /** @return array<string, int> وسوم المشاريع المنشورة مع تكرار كل وسم. */
    protected function collectTags(): array
    {
        $tags = [];

        Project::query()
            ->published()
            ->whereNotNull('tags')
            ->orderByDesc('view_count')
            ->limit(500)
            ->pluck('tags')
            ->each(function ($projectTags) use (&$tags) {
                foreach ((array) $projectTags as $tag) {
                    $tag = trim((string) $tag);
                    if ($tag !== '') {
                        $tags[$tag] = ($tags[$tag] ?? 0) + 1;
                    }
                }
            });

        return $tags;
    }
}
