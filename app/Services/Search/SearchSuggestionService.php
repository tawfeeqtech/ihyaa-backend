<?php

namespace App\Services\Search;

use App\Exceptions\Search\SearchUnavailableException;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * اقتراحات البحث التلقائية — search-api.md §2 (T131).
 *
 * US-031 · FR-241:
 *  - حد أدنى حرفان (أقل ← InvalidArgumentException تُترجم 422 QUERY_TOO_SHORT).
 *  - limit=5 + attributesToRetrieve=["title","tags"] + attributesToHighlight=["title"].
 *  - نوعان: project_title (مع project_id + highlighted) + tag — بدون تكرار.
 *  - كاش Redis `search:suggestions:{q}` — TTL 10 دقائق.
 */
class SearchSuggestionService
{
    public const LIMIT = 5;

    public const MIN_LENGTH = 2;

    public const CACHE_TTL = 600; // 10 دقائق

    public function __construct(protected SearchQueryBuilder $queryBuilder)
    {
    }

    /**
     * اقتراحات لعبارة معيّنة — تعيد حمولة الاستجابة (بدون الغلاف الخارجي).
     *
     * @return array{query:string, suggestions:list<array<string, mixed>>, took_ms:int}
     *
     * @throws \InvalidArgumentException  عندما تكون العبارة أقصر من حرفين.
     * @throws SearchUnavailableException  عندما يكون Meilisearch معطلاً.
     */
    public function suggest(string $q): array
    {
        $q = mb_substr(trim($q), 0, SearchQueryBuilder::MAX_Q_LENGTH);

        if (mb_strlen($q) < self::MIN_LENGTH) {
            throw new \InvalidArgumentException('QUERY_TOO_SHORT');
        }

        // كاش Redis 10 دقائق لكل عبارة مميزة (search-api.md §2).
        return Cache::remember('search:suggestions:'.$q, self::CACHE_TTL, fn () => $this->execute($q));
    }

    /**
     * تنفيذ الاستعلام الفعلي + تجميع الاقتراحات.
     *
     * @return array{query:string, suggestions:list<array<string, mixed>>, took_ms:int}
     */
    protected function execute(string $q): array
    {
        $start = microtime(true);

        $raw = $this->query($q);

        $tookMs = (int) max(round((microtime(true) - $start) * 1000), (int) ($raw['processingTimeMs'] ?? 0));

        return [
            'query' => $q,
            'suggestions' => $this->collect($raw['hits'] ?? [], $q),
            'took_ms' => $tookMs,
        ];
    }

    /**
     * استعلام Meilisearch عبر Scout raw() — يُغلَّف فشل الاتصال.
     *
     * @return array<string, mixed>
     */
    protected function query(string $q): array
    {
        try {
            $raw = Project::search($q)
                ->options([
                    'attributesToRetrieve' => ['title', 'tags'],
                    'attributesToHighlight' => ['title'],
                ])
                ->take(self::LIMIT)
                ->raw();

            return is_array($raw) ? $raw : [];
        } catch (Throwable $e) {
            throw new SearchUnavailableException(previous: $e);
        }
    }

    /**
     * تجميع project_title + tag — بدون تكرار — حتى LIMIT.
     *
     * @param  list<array<string, mixed>>  $hits
     * @return list<array<string, mixed>>
     */
    protected function collect(array $hits, string $q): array
    {
        $suggestions = [];
        $seenTitles = [];
        $seenTags = [];

        $needle = mb_strtolower($q);

        foreach ($hits as $hit) {
            $title = is_string($hit['title'] ?? null) ? $hit['title'] : '';

            if ($title !== '' && ! isset($seenTitles[$title])) {
                $seenTitles[$title] = true;
                $formatted = $hit['_formatted']['title'] ?? $title;

                $suggestions[] = [
                    'type' => 'project_title',
                    'text' => $title,
                    'project_id' => (int) ($hit['id'] ?? 0),
                    'highlighted' => $this->highlight($formatted),
                ];
            }

            foreach (is_array($hit['tags'] ?? null) ? $hit['tags'] : [] as $tag) {
                if (count($suggestions) >= self::LIMIT) {
                    break 2;
                }

                if (! is_string($tag) || $tag === '' || isset($seenTags[$tag])) {
                    continue;
                }

                // tag يُقترح فقط إذا احتوى على العبارة (حساسية حالات بسيطة).
                if (mb_strpos(mb_strtolower($tag), $needle) === false) {
                    continue;
                }

                $seenTags[$tag] = true;
                $suggestions[] = ['type' => 'tag', 'text' => $tag];
            }

            if (count($suggestions) >= self::LIMIT) {
                break;
            }
        }

        return array_slice($suggestions, 0, self::LIMIT);
    }

    /**
     * تحويل تمييز Meilisearch `<em>…</em>` إلى `<strong>…</strong>` (شكل العقد §2).
     */
    protected function highlight(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        return (string) preg_replace('/<em>(.*?)<\/em>/u', '<strong>$1</strong>', $text);
    }
}
