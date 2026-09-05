<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Meilisearch\Client;
use Throwable;

/**
 * مزامنة إعدادات فهرس Meilisearch — data-model §8.2 (T115 · US-030).
 *
 * الأمر: `meilisearch:sync-settings`
 *
 * يطبّق إعدادات الفهرس كاملة (searchable/filterable/sortable + rankingRules +
 * typoTolerance + localizedAttributes ar/en + dictionary + synonyms + stopWords)
 * عبر Meilisearch Client مباشرة — نفس الفهرس الذي يستخدمه Scout (`projects_index`).
 */
class SyncMeilisearchSettings extends Command
{
    protected $signature = 'meilisearch:sync-settings';

    protected $description = 'تطبيق إعدادات فهرس البحث (searchable/filterable/sortable + localizedAttributes ar/en + synonyms) على Meilisearch';

    public function handle(): int
    {
        $host = (string) config('scout.meilisearch.host', 'http://localhost:7700');
        $key = (string) config('scout.meilisearch.key', '');

        if ($key === '') {
            $this->error('MEILISEARCH_KEY غير مضبوطة في .env — لا يمكن مزامنة الإعدادات.');

            return self::FAILURE;
        }

        try {
            $client = new Client($host, $key);

            $task = $client->index('projects_index')->updateSettings($this->settings());

            $this->info("تم إرسال مزامنة الإعدادات — taskUid: {$task['taskUid']}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('فشل مزامنة إعدادات Meilisearch: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * إعدادات الفهرس — data-model §8.2 (بدون uid/primaryKey — ليست من مدخلات updateSettings).
     *
     * @return array<string, mixed>
     */
    protected function settings(): array
    {
        return [
            'searchableAttributes' => ['title', 'description', 'category', 'tags'],
            'filterableAttributes' => ['category', 'status', 'overall_score', 'has_score', 'tags', 'created_at', 'user_id'],
            'sortableAttributes' => ['overall_score', 'created_at', 'views_count'],
            'rankingRules' => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness'],
            'typoTolerance' => [
                'enabled' => true,
                'minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8],
                'disableOnWords' => [],
                'disableOnAttributes' => [],
            ],
            'pagination' => ['maxTotalHits' => 10000],
            'localizedAttributes' => [
                ['locales' => ['ar'], 'attributePatterns' => ['title', 'description', 'category', 'tags']],
                ['locales' => ['en'], 'attributePatterns' => ['title', 'description', 'category', 'tags']],
            ],
            'dictionary' => ['إحياء', 'تخرج', 'نموذج أولي', 'حاضنة'],
            'stopWords' => [],
            'synonyms' => [
                'ai' => ['ذكاء اصطناعي', 'الذكاء الاصطناعي', 'machine learning'],
                'startup' => ['شركة ناشئة', 'ناشئة'],
                'app' => ['تطبيق', 'تطبيقات'],
            ],
            'separatorTokens' => [],
            'nonSeparatorTokens' => ['-', '_'],
        ];
    }
}
