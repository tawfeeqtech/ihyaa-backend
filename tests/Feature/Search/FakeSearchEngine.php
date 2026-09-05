<?php

namespace Tests\Feature\Search;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;

/**
 * محرك بحث وهمي (in-memory) لاختبارات EPIC-06.
 *
 * يحاكي Meilisearch بما يكفي لاختبار الفلاتر/الفرز/الترقيم/الأوجه
 * (استجابة raw بالشكل الذي ينتجه MeilisearchEngine). يُسجَّل عبر
 * EngineManager::extend('fake', ...) مع config(['scout.driver' => 'fake']).
 *
 * الوثائق تُخزَّن من `toSearchableArray()` عند `update()`/`flush()`.
 */
class FakeSearchEngine extends Engine
{
    /** @var array<string, array<string, mixed>> id → وثيقة */
    private static array $documents = [];

    /** عند true تُحاكى الأعطال (T114 · SEARCH_UNAVAILABLE) */
    private static bool $shouldFail = false;

    public static function flushAll(): void
    {
        self::$documents = [];
        self::$shouldFail = false;
    }

    /** يحاكي انقطاع Meilisearch لاختبار 503 SEARCH_UNAVAILABLE */
    public static function failNextSearch(): void
    {
        self::$shouldFail = true;
    }

    /**
     * حقن وثائق جاهزة مباشرة (اختبار الأداء T120 — 1000 وثيقة بسرعة).
     *
     * @param  list<array<string, mixed>>  $documents
     */
    public static function seed(array $documents): void
    {
        foreach ($documents as $doc) {
            self::$documents[(string) ($doc['id'] ?? 0)] = $doc;
        }
    }

    /** @return array<string, array<string, mixed>> */
    public static function documents(): array
    {
        return self::$documents;
    }

    public function update($models): void
    {
        foreach ($models as $model) {
            $data = $model->toSearchableArray();

            if (empty($data)) {
                continue;
            }

            self::$documents[(string) $model->getScoutKey()] = $data;
        }
    }

    public function delete($models): void
    {
        foreach ($models as $model) {
            unset(self::$documents[(string) $model->getScoutKey()]);
        }
    }

    public function search(Builder $builder): array
    {
        if (self::$shouldFail) {
            throw new \RuntimeException('Meilisearch connection refused (fake)');
        }

        return $this->performSearch($builder);
    }

    public function paginate(Builder $builder, $perPage, $page): array
    {
        return $this->performSearch($builder, $perPage, $page);
    }

    public function mapIds($results): Collection
    {
        $ids = collect($results['hits'] ?? [])->pluck('id');

        return new Collection($ids->all());
    }

    public function map(Builder $builder, $results, $model): Collection
    {
        return new Collection();
    }

    public function lazyMap(Builder $builder, $results, $model): LazyCollection
    {
        return LazyCollection::make($this->map($builder, $results, $model));
    }

    public function getTotalCount($results): int
    {
        return (int) ($results['totalHits'] ?? 0);
    }

    public function flush($model): void
    {
        self::$documents = [];
    }

    public function createIndex($name, array $options = []): void
    {
        // no-op
    }

    public function deleteIndex($name): void
    {
        self::$documents = [];
    }

    /**
     * محاكاة rawSearch من Meilisearch:
     * filter → sort → page/hitsPerPage → facets + highlights.
     *
     * @param  int|null  $overridePerPage
     * @param  int|null  $overridePage
     * @return array<string, mixed>
     */
    protected function performSearch(Builder $builder, ?int $overridePerPage = null, ?int $overridePage = null): array
    {
        $q = (string) $builder->query;
        $options = $builder->options;
        $filter = (string) ($options['filter'] ?? '');
        $sort = $options['sort'] ?? [];
        $facets = $options['facets'] ?? [];
        $page = (int) ($overridePage ?? $options['page'] ?? 1);
        $perPage = (int) ($overridePerPage ?? $builder->limit ?? 12);

        // 1) تصفية
        $matches = array_filter(self::$documents, function (array $doc) use ($filter, $q) {
            if (! $this->matchesQuery($doc, $q)) {
                return false;
            }

            return $filter === '' ? true : $this->evaluateFilter($filter, $doc);
        });

        $total = count($matches);

        // 2) فرز
        $this->sortDocuments($matches, $sort, $q);

        // 3) أوجه (على المجموعة بعد الفلترة وقبل الترقيم)
        $facetDistribution = [];
        foreach ($facets as $facet) {
            $facetDistribution[$facet] = $this->countFacet($matches, $facet);
        }

        // 4) ترقيم
        $totalPages = (int) max(1, ceil($total / max(1, $perPage)));
        $page = max(1, min($page, $totalPages));
        $slice = array_slice(array_values($matches), ($page - 1) * $perPage, $perPage);

        $hits = array_map(fn (array $doc) => $this->withFormatted($doc, $q, $options), $slice);

        return [
            'hits' => array_values($hits),
            'query' => $q,
            'processingTimeMs' => 1,
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'estimatedTotalHits' => $total,
            'totalHits' => $total,
            'totalPages' => $totalPages,
            'page' => $page,
            'hitsPerPage' => $perPage,
            'facetDistribution' => $facetDistribution,
            'facetStats' => [],
        ];
    }

    /** مطابقة نصية مبسطة: أي حد من q يظهر في حقل قابل للبحث */
    protected function matchesQuery(array $doc, string $q): bool
    {
        if (trim($q) === '') {
            return true;
        }

        $haystack = mb_strtolower(implode(' ', [
            (string) ($doc['title'] ?? ''),
            (string) ($doc['description'] ?? ''),
            (string) ($doc['category'] ?? ''),
            implode(' ', (array) ($doc['tags'] ?? [])),
        ]));

        foreach (preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) as $term) {
            if (mb_strpos($haystack, mb_strtolower($term)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * فرز الوثائق:
     *  - sort صريح (خريطة SearchQueryBuilder): أول معيار، ثم معايير لاحقة.
     *  - null (overall_score) يُرصَّف أخيراً دائماً.
     *  - بلا sort: ترتيب إدراج مستقر.
     *
     * @param  array<string, mixed>  $docs  (بالمفتاح id)
     * @param  list<string>  $sort
     */
    protected function sortDocuments(array &$docs, array $sort, string $q): void
    {
        $values = array_values($docs);

        if (empty($sort)) {
            $docs = $values;

            return;
        }

        usort($values, function (array $a, array $b) use ($sort, $q) {
            foreach ($sort as $rule) {
                [$field, $dir] = array_pad(explode(':', $rule), 2, 'asc');

                $av = $a[$field] ?? null;
                $bv = $b[$field] ?? null;

                // nulls أخيراً (US-033-S5)
                if ($av === null && $bv === null) {
                    continue;
                }
                if ($av === null) {
                    return 1;
                }
                if ($bv === null) {
                    return -1;
                }

                $cmp = $av <=> $bv;

                if ($cmp !== 0) {
                    return $dir === 'desc' ? -$cmp : $cmp;
                }
            }

            return 0;
        });

        $docs = $values;
    }

    /** @param  list<string>  $sort  */
    protected function evaluateFilter(string $filter, array $doc): bool
    {
        $parser = new FakeFilterParser($doc);

        return $parser->parse($filter);
    }

    /**
     * @param  array<string, mixed>  $docs
     * @return array<string, int>
     */
    protected function countFacet(array $docs, string $facet): array
    {
        $counts = [];

        foreach ($docs as $doc) {
            $value = $doc[$facet] ?? null;

            foreach (is_array($value) ? $value : [$value] as $v) {
                if ($v === null || $v === '') {
                    continue;
                }

                $key = (string) $v;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * إضافة `_formatted` (تمييز العنوان) عندما يُطلب attributesToHighlight.
     *
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function withFormatted(array $doc, string $q, array $options): array
    {
        $highlight = ! empty($options['attributesToHighlight'] ?? []);

        if (! $highlight) {
            return $doc;
        }

        $title = (string) ($doc['title'] ?? '');
        $formatted = ['title' => $this->highlight($title, $q)];

        if (! empty($options['attributesToCrop'] ?? [])) {
            $description = (string) ($doc['description'] ?? '');
            $formatted['description'] = mb_strlen($description) > 120
                ? mb_substr($description, 0, 120).'…'
                : $description;
        }

        $doc['_formatted'] = $formatted;

        return $doc;
    }

    protected function highlight(string $text, string $q): string
    {
        if (trim($q) === '') {
            return $text;
        }

        foreach (preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) as $term) {
            if ($term === '') {
                continue;
            }

            $text = (string) preg_replace(
                '/(?<!<em>)'.preg_quote($term, '/').'(?!<\/em>)/iu',
                '<em>$0</em>',
                $text
            );
        }

        return $text;
    }
}
