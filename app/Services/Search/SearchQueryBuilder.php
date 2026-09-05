<?php

namespace App\Services\Search;

use Illuminate\Http\Request;

/**
 * تحويل معايير URL → استعلام Meilisearch — search-api.md §3 · data-model §8.2/§8.3.
 *
 * المسؤوليات (T122 · T136 · T141):
 *  - صياغة سلسلة `filter` وفق قواعد تراكم AND (US-032-S2).
 *  - تعقيم whitelist: كل قيمة فلتر تُقيَّد بالقوائم المسموحة، والقيم غير الصالحة
 *    تُتجاهل بأمان (لا 500) — US-035-S4.
 *  - `q` يُقتطع عند 100 حرف.
 *  - نطاق الدرجة يضيف `has_score = true`.
 *  - خريطة الفرز (score/created_at/views_count × asc/desc) — US-033 · data-model §8.3.
 *  - الحالة الافتراضية الآمنة: كل المشاريع المنشورة، فرز بالتقييم تنازلياً — US-035.
 */
class SearchQueryBuilder
{
    public const SORTS = ['score', 'created_at', 'views_count'];

    public const DIRS = ['asc', 'desc'];

    public const STATUSES = ['completed', 'needs_development', 'needs_funding'];

    public const DEFAULT_SORT = 'score';

    public const DEFAULT_DIR = 'desc';

    public const MAX_Q_LENGTH = 100;

    public const DEFAULT_PER_PAGE = 12;

    public const MAX_PER_PAGE = 24;

    /** الأنماط المسموحة للفهارس — data-model §8.2 */
    public const FACETS = ['category', 'status', 'tags'];

    /**
     * ترجمة استعلام HTTP إلى بنية بحث جاهزة لمحرك Meilisearch.
     *
     * @return array{
     *   q: string,
     *   filter: string,
     *   sort: list<string>,
     *   facets: list<string>,
     *   facets_enabled: bool,
     *   page: int,
     *   perPage: int,
     *   sort_key: string,
     *   dir: string,
     *   applied_filters: array<string, mixed>
     * }
     */
    public function build(Request $request): array
    {
        $q = $this->sanitizeQuery($request->input('q'));
        $sector = $this->sanitizeSector($request->input('sector'));
        $scoreMin = $this->clampScore($request->input('score_min'));
        $scoreMax = $this->clampScore($request->input('score_max'));
        $status = $this->sanitizeStatus($request->input('status'));
        $tags = $this->sanitizeTags($request->input('tags', []));
        $createdFrom = $this->sanitizeDate($request->input('created_from'));
        $createdTo = $this->sanitizeDate($request->input('created_to'));

        $sort = $this->sanitizeSort($request->input('sort'));
        $dir = $this->sanitizeDir($request->input('dir'));

        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->input('per_page', self::DEFAULT_PER_PAGE)));

        return [
            'q' => $q,
            'filter' => $this->buildFilter($sector, $scoreMin, $scoreMax, $status, $tags, $createdFrom, $createdTo),
            'sort' => $this->buildSort($sort, $dir),
            'facets' => $request->boolean('facets', true) ? self::FACETS : [],
            'facets_enabled' => $request->boolean('facets', true),
            'page' => $page,
            'perPage' => $perPage,
            'sort_key' => $sort,
            'dir' => $dir,
            'applied_filters' => $this->appliedFilters($q, $sector, $scoreMin, $scoreMax, $status, $tags, $createdFrom, $createdTo, $sort, $dir),
        ];
    }

    /**
     * سلسلة الفلترة — كل الفلاتر تُدمج بـ AND (US-032-S2 · search-api.md §3).
     * بدون فلاتر: كل المنشور (`has_score = true OR has_score = false`).
     */
    private function buildFilter(
        ?string $sector,
        ?int $scoreMin,
        ?int $scoreMax,
        ?string $status,
        array $tags,
        ?string $createdFrom,
        ?string $createdTo,
    ): string {
        $parts = [];

        if ($sector !== null) {
            $parts[] = 'category = "'.$sector.'"';
        }

        if ($scoreMin !== null || $scoreMax !== null) {
            $range = [];
            if ($scoreMin !== null) {
                $range[] = 'overall_score >= '.$scoreMin;
            }
            if ($scoreMax !== null) {
                $range[] = 'overall_score <= '.$scoreMax;
            }
            $range[] = 'has_score = true';
            $parts[] = '('.implode(' AND ', $range).')';
        }

        if ($status !== null) {
            $parts[] = 'status = "'.$status.'"';
        }

        if (! empty($tags)) {
            $parts[] = 'tags IN ['.implode(', ', array_map(fn (string $t) => '"'.$t.'"', $tags)).']';
        }

        if ($createdFrom !== null) {
            $parts[] = 'created_at >= '.$this->dateToTimestamp($createdFrom);
        }

        if ($createdTo !== null) {
            $parts[] = 'created_at <= '.$this->dateToTimestamp($createdTo);
        }

        return empty($parts)
            ? 'has_score = true OR has_score = false'
            : implode(' AND ', $parts);
    }

    /**
     * خريطة الفرز — search-api.md §1 (US-033 · data-model §8.3).
     * score → ["overall_score:dir","created_at:dir"] (nulls أخيراً تلقائياً في Meilisearch).
     */
    private function buildSort(string $sort, string $dir): array
    {
        return match ($sort) {
            'created_at' => ["created_at:{$dir}"],
            'views_count' => ["views_count:{$dir}"],
            default => ["overall_score:{$dir}", 'created_at:'.$dir],
        };
    }

    /** q — يُقتطع عند 100 حرف (search-api.md §1) */
    private function sanitizeQuery(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return mb_substr(trim($value), 0, self::MAX_Q_LENGTH);
    }

    /** sector — whitelist من أسماء التصنيفات المسموحة (category slugs) */
    private function sanitizeSector(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return mb_substr($value, 0, 120);
    }

    /** score — يُقرَّب إلى [0,100] (score_min=999 → 100) */
    private function clampScore(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) max(0, min(100, (int) round((float) $value)));
    }

    /** status — whitelist من الحالات التجارية الثلاث */
    private function sanitizeStatus(mixed $value): ?string
    {
        if (! is_string($value) || ! in_array($value, self::STATUSES, true)) {
            return null;
        }

        return $value;
    }

    /**
     * tags[] — أحرف `[a-zA-Z0-9؀-ۿ _-]` فقط (data-model §8.3) — بقية القيم تُتجاهل.
     *
     * @return list<string>
     */
    private function sanitizeTags(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $clean = [];

        foreach ($values as $tag) {
            if (! is_string($tag) || $tag === '') {
                continue;
            }

            $tag = trim($tag);

            if ($tag === '' || mb_strlen($tag) > 50 || ! preg_match('/^[\p{L}\p{N} _\-]+$/u', $tag)) {
                continue;
            }

            $clean[] = $tag;
        }

        return array_values(array_unique($clean));
    }

    /** تاريخ ISO → Y-m-d أو null عند الصلاحية */
    private function sanitizeDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $date = \DateTime::createFromFormat('Y-m-d', $value);

        if (! $date) {
            try {
                $date = new \DateTime($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return $date->format('Y-m-d');
    }

    /** sort — whitelist {score, created_at, views_count} — غير الصالح → score (افتراضي آمن) */
    private function sanitizeSort(mixed $value): string
    {
        return is_string($value) && in_array($value, self::SORTS, true) ? $value : self::DEFAULT_SORT;
    }

    /** dir — whitelist {asc, desc} — غير الصالح → desc */
    private function sanitizeDir(mixed $value): string
    {
        return is_string($value) && in_array($value, self::DIRS, true) ? $value : self::DEFAULT_DIR;
    }

    private function dateToTimestamp(string $date): int
    {
        return (new \DateTime($date))->getTimestamp();
    }

    /** الفلاتر المطبَّقة فعلياً — تُعاد في الاستجابة (search-api.md §1) */
    private function appliedFilters(
        string $q,
        ?string $sector,
        ?int $scoreMin,
        ?int $scoreMax,
        ?string $status,
        array $tags,
        ?string $createdFrom,
        ?string $createdTo,
        string $sort,
        string $dir,
    ): array {
        $filters = ['sort' => $sort, 'dir' => $dir];

        if ($q !== '') {
            $filters['q'] = $q;
        }
        if ($sector !== null) {
            $filters['sector'] = $sector;
        }
        if ($scoreMin !== null) {
            $filters['score_min'] = $scoreMin;
        }
        if ($scoreMax !== null) {
            $filters['score_max'] = $scoreMax;
        }
        if ($status !== null) {
            $filters['status'] = $status;
        }
        if (! empty($tags)) {
            $filters['tags'] = $tags;
        }
        if ($createdFrom !== null) {
            $filters['created_from'] = $createdFrom;
        }
        if ($createdTo !== null) {
            $filters['created_to'] = $createdTo;
        }

        return $filters;
    }
}
