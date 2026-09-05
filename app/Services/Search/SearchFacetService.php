<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * أعداد النتائج لكل خيار فلتر (FR-243) — search-api.md §1.
 *
 * SearchFacetService (T123):
 *  - `fromRaw()`: يطبّع `facetDistribution` من Meilisearch
 *    ({category, status, tags}) إلى شكل الاستجابة ({sector, status, tags}).
 *  - `rememberForFilter()`: كاش Redis 60 ثانية لكل مجموعة فلاتر مميزة
 *    (`search:facets:{md5(filter)}`) — يمنع إعادة حساب الأعداد المتكررة.
 */
class SearchFacetService
{
    public const CACHE_TTL = 60; // ثانية

    /**
     * تطبيع توزيع الأوجه من Meilisearch إلى شكل العقد (search-api.md §1).
     *
     * @return array<string, array<string, int>>
     */
    public function fromRaw(?array $distribution): array
    {
        $distribution = is_array($distribution) ? $distribution : [];

        return [
            'sector' => $distribution['category'] ?? [],
            'status' => $distribution['status'] ?? [],
            'tags' => $distribution['tags'] ?? [],
        ];
    }

    /**
     * قراءة/كتابة كاش Redis 60s لأعداد الأوجه — مفتاح مميز بسلسلة الفلترة.
     *
     * @template T of array
     *
     * @param  callable(): T  $loader
     * @return T
     */
    public function rememberForFilter(string $filter, callable $loader): array
    {
        $key = 'search:facets:'.md5($filter);

        try {
            return Cache::remember($key, self::CACHE_TTL, $loader);
        } catch (Throwable) {
            // Redis غير متاح — لا نكسر البحث؛ نعيد الحساب المباشر.
            return $loader();
        }
    }
}
