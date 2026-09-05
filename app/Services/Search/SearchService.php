<?php

namespace App\Services\Search;

use App\Exceptions\Search\SearchUnavailableException;
use App\Models\Project;
use Illuminate\Http\Request;
use Throwable;

/**
 * خدمة البحث المتقدم — search-api.md §1 (T116 · T142).
 *
 * SRS-API-20: hits + pagination + facets + applied_filters + took_ms + permalinks.
 *
 * التنفيذ عبر Laravel Scout `raw()` — يُرجع استجابة Meilisearch الخام
 * (hits/_formatted/facetDistribution/processingTimeMs/totalHits) مباشرة،
 * مع تمرير `filter`/`sort`/`facets`/`page` عبر options (لا `->where`/`->orderBy`
 * كي لا تتعارض خريطة الفرز المخصّصة مع builder).
 *
 * Meilisearch معطّل ← SearchUnavailableException (503 SEARCH_UNAVAILABLE retryable).
 */
class SearchService
{
    public function __construct(
        protected SearchQueryBuilder $queryBuilder,
        protected SearchFacetService $facets,
    ) {
    }

    /**
     * تنفيذ بحث متقدم وإرجاع حمولة الاستجابة (بدون الغلاف الخارجي).
     *
     * @return array{
     *   hits: list<array<string, mixed>>,
     *   pagination: array{page:int, per_page:int, total:int, total_pages:int},
     *   facets: array<string, array<string, int>>,
     *   applied_filters: array<string, mixed>,
     *   took_ms: int,
     *   permalinks: array{self:string, ui:string}
     * }
     */
    public function search(Request $request): array
    {
        $query = $this->queryBuilder->build($request);

        $start = microtime(true);

        $raw = $this->execute($query);

        $tookMs = (int) max(round((microtime(true) - $start) * 1000), (int) ($raw['processingTimeMs'] ?? 0));

        $hits = $this->mapHits($raw['hits'] ?? []);
        $total = (int) ($raw['totalHits'] ?? count($hits));
        $perPage = $query['perPage'];
        $totalPages = (int) ($raw['totalPages'] ?? max(1, (int) ceil($total / max(1, $perPage))));

        // أعداد الأوجه — كاش Redis 60s لكل مجموعة فلاتر مميزة (T123 · FR-243).
        // `facets=false` (contract §1) يعطّل حساب الأوجه لتحسين السرعة.
        $facets = $query['facets_enabled']
            ? $this->facets->rememberForFilter($query['filter'], fn () => $this->facets->fromRaw($raw['facetDistribution'] ?? null))
            : [];

        return [
            'hits' => $hits,
            'pagination' => [
                'page' => $query['page'],
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
            'facets' => $facets,
            'applied_filters' => $query['applied_filters'],
            'took_ms' => $tookMs,
            'permalinks' => $this->buildPermalinks($query),
        ];
    }

    /**
     * تنفيذ استعلام Meilisearch عبر Scout raw() — يُغلَّف استثناء الاتصال.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function execute(array $query): array
    {
        try {
            $raw = Project::search($query['q'])
                ->options([
                    'filter' => $query['filter'],
                    'sort' => $query['sort'],
                    'facets' => $query['facets'],
                    'page' => $query['page'],
                    'attributesToHighlight' => ['title'],
                    'attributesToCrop' => ['description' => ['length' => 120]],
                ])
                ->take($query['perPage'])
                ->raw();

            return is_array($raw) ? $raw : [];
        } catch (Throwable $e) {
            throw new SearchUnavailableException(previous: $e);
        }
    }

    /**
     * تنسيق hits وفق العقد — search-api.md §1.
     *
     * @param  list<array<string, mixed>>  $hits
     * @return list<array<string, mixed>>
     */
    protected function mapHits(array $hits): array
    {
        if (empty($hits)) {
            return [];
        }

        $covers = $this->coverMap($hits);

        return array_values(array_map(function (array $hit) use ($covers) {
            $id = (int) ($hit['id'] ?? 0);
            $formatted = $hit['_formatted'] ?? [];

            return [
                'id' => $id,
                'title' => $hit['title'] ?? '',
                'description_snippet' => $this->snippet($hit, $formatted),
                'category' => $hit['category'] ?? null,
                'tags' => $hit['tags'] ?? [],
                'status' => $hit['status'] ?? null,
                'overall_score' => $hit['overall_score'] ?? null,
                'has_score' => (bool) ($hit['has_score'] ?? false),
                'views_count' => (int) ($hit['views_count'] ?? 0),
                'created_at' => isset($hit['created_at'])
                    ? \Illuminate\Support\Carbon::createFromTimestampUTC((int) $hit['created_at'])->toISOString()
                    : null,
                'cover_image_url' => $covers[$id] ?? null,
                '_formatted' => is_array($formatted) ? $formatted : [],
            ];
        }, $hits));
    }

    /**
     * مقتطف الوصف — من `_formatted.description` المقتطع (بلا وسوم) أو اختصار خام.
     *
     * @param  array<string, mixed>  $hit
     * @param  array<string, mixed>  $formatted
     */
    protected function snippet(array $hit, array $formatted): ?string
    {
        $crop = $formatted['description'] ?? null;

        if (is_string($crop) && $crop !== '') {
            return trim(strip_tags($crop));
        }

        $description = $hit['description'] ?? null;

        if (! is_string($description) || $description === '') {
            return null;
        }

        return mb_strlen($description) > 120
            ? mb_substr($description, 0, 120).'…'
            : $description;
    }

    /**
     * خريطة id → cover_image_url من قاعدة البيانات (تجنب N+1 عبر whereIn).
     *
     * @param  list<array<string, mixed>>  $hits
     * @return array<int, string|null>
     */
    protected function coverMap(array $hits): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn (array $hit) => (int) ($hit['id'] ?? 0),
            $hits,
        ))));

        if (empty($ids)) {
            return [];
        }

        $map = [];

        Project::query()
            ->with(['files' => fn ($q) => $q->where('type', 'image')])
            ->whereIn('id', $ids)
            ->get()
            ->each(function (Project $project) use (&$map) {
                $map[(int) $project->id] = $project->coverUrl();
            });

        return $map;
    }

    /**
     * الروابط الدائمة — US-035 · search-api.md §1 (T142).
     * `self` = رابط API يعيد بناء نفس الحالة · `ui` = رابط الواجهة.
     *
     * @param  array<string, mixed>  $query
     * @return array{self:string, ui:string}
     */
    protected function buildPermalinks(array $query): array
    {
        $applied = $query['applied_filters'];

        $parts = [];

        $append = function (string $key, mixed $value) use (&$parts): void {
            if (is_array($value)) {
                // tags[]=a&tags[]=b — search-api.md §1 (tags%5B%5D)
                foreach (array_values($value) as $item) {
                    $parts[] = rawurlencode($key.'[]').'='.rawurlencode((string) $item);
                }

                return;
            }

            $parts[] = rawurlencode($key).'='.rawurlencode((string) $value);
        };

        // الترتيب القياسي — search-api.md §1 (filters ثم sort/dir ثم page/per_page)
        foreach (['q', 'sector', 'score_min', 'score_max', 'status', 'tags', 'created_from', 'created_to', 'sort', 'dir'] as $key) {
            if (array_key_exists($key, $applied)) {
                $append($key, $applied[$key]);
            }
        }

        $append('page', $query['page']);

        if ($query['perPage'] !== SearchQueryBuilder::DEFAULT_PER_PAGE) {
            $append('per_page', $query['perPage']);
        }

        $qs = implode('&', $parts);

        return [
            'self' => '/api/search'.($qs !== '' ? '?'.$qs : ''),
            'ui' => '/search'.($qs !== '' ? '?'.$qs : ''),
        ];
    }
}
