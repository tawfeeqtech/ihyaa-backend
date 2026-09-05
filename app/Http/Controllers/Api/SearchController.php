<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Search\SearchUnavailableException;
use App\Services\Search\SearchQueryBuilder;
use App\Services\Search\SearchService;
use App\Services\Search\SearchSuggestionService;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * البحث — SRS-API-20/21 · search-api.md (T116 · T131).
 *
 * الفهرس عبر Laravel Scout + Meilisearch (مزامنة تلقائية عبر ProjectObserver —
 * plan §5.1/§5.3 · US-034). الفهرس يضم المنشور وغير المحذوف فقط (FR-247).
 *
 * الأخطاء:
 *  - Meilisearch معطّل ← 503 `SEARCH_UNAVAILABLE` retryable:true (SRS-UI-28).
 *  - اقتراحات بأقل من حرفين ← 422 `QUERY_TOO_SHORT` (search-api.md §2).
 */
class SearchController
{
    use ApiResponse;

    /** نصوص حالة «لا نتائج» — search-api.md §1 (US-030-S6) */
    private const EMPTY_SUGGESTIONS = ['جرّب مصطلحات أوسع', 'ألغِ بعض الفلاتر'];

    public function __construct(
        protected SearchService $search,
        protected SearchSuggestionService $suggestionsService,
    ) {
    }

    /**
     * GET /api/search — بحث متقدم مع فلاتر وفرز وترقيم وأوجه (SRS-API-20).
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $result = $this->search->search($request);
        } catch (SearchUnavailableException) {
            return $this->searchUnavailable();
        } catch (Throwable $e) {
            report($e);

            return $this->searchUnavailable();
        }

        $total = $result['pagination']['total'];

        // حالة «لا نتائج» — search-api.md §1 · US-030-S6
        if ($total === 0) {
            return $this->success(
                $result,
                'ok',
                200,
                [
                    'suggestions' => self::EMPTY_SUGGESTIONS,
                    'actions' => [
                        'browse_all_url' => '/api/search?sort=score&dir=desc',
                    ],
                    'meta' => ['code' => 'empty', 'cached' => false],
                ]
            );
        }

        return $this->success($result, 'ok', 200, [
            'meta' => ['code' => 'ok', 'cached' => false],
        ]);
    }

    /**
     * GET /api/search/suggestions — اقتراحات تلقائية (SRS-API-21 · US-031).
     */
    public function suggestions(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'max:'.SearchQueryBuilder::MAX_Q_LENGTH],
        ]);

        $q = (string) $request->input('q');

        if (mb_strlen(trim($q)) < SearchSuggestionService::MIN_LENGTH) {
            return $this->unprocessable(
                'QUERY_TOO_SHORT',
                'أدخل حرفين على الأقل',
                ['q' => ['الحد الأدنى حرفان']]
            );
        }

        try {
            $result = $this->suggestionsService->suggest($q);
        } catch (SearchUnavailableException) {
            return $this->searchUnavailable();
        } catch (Throwable $e) {
            report($e);

            return $this->searchUnavailable();
        }

        return $this->success($result, 'ok', 200, [
            'meta' => ['code' => 'ok'],
        ]);
    }

    /** Meilisearch معطّل — search-api.md §1 · SRS-UI-28 (503 retryable) */
    private function searchUnavailable(): JsonResponse
    {
        return $this->error(
            'SEARCH_UNAVAILABLE',
            __('search.unavailable'),
            503,
            [],
            ['retryable' => true]
        );
    }
}
