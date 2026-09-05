<?php

namespace Tests\Feature\Search;

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
uses(InteractsWithFakeSearch::class);

beforeEach(function () {
    $this->useFakeSearchEngine();

    $docs = [];

    for ($i = 1; $i <= 1000; $i++) {
        $isArabic = $i % 2 === 0;
        $hasScore = $i % 3 !== 0; // ~2/3 بتقييم

        $docs[] = [
            'id' => (string) $i,
            'title' => $isArabic ? "منصة تشخيص طبي رقم {$i}" : "AI Medical Platform {$i}",
            'description' => $isArabic
                ? 'نظام ذكي لتحليل البيانات الطبية وتقديم توصيات دقيقة للأطباء.'
                : 'A smart system for analyzing medical data and providing accurate doctor recommendations.',
            'category' => $i % 2 === 0 ? 'health' : 'fintech',
            'tags' => $i % 2 === 0 ? ['ai', 'health'] : ['ai', 'fintech'],
            'status' => match ($i % 3) {
                0 => 'completed',
                1 => 'needs_funding',
                default => 'needs_development',
            },
            'overall_score' => $hasScore ? round(40 + ($i % 60), 1) : null,
            'has_score' => $hasScore,
            'views_count' => $i * 3,
            'created_at' => now()->subDays($i)->getTimestamp(),
            'user_id' => '1',
        ];
    }

    FakeSearchEngine::seed($docs);
});

// ———————————————————————— T120 ————————————————————————

it('serves search at volume (1000 indexed documents) with a valid took_ms', function () {
    $start = microtime(true);

    $this->getJson('/api/search')
        ->assertStatus(200)
        ->assertJsonPath('data.pagination.total', 1000)
        ->assertJsonPath('data.pagination.total_pages', 84)
        ->assertJsonPath('data.pagination.per_page', 12)
        ->assertJsonCount(12, 'data.hits')
        ->assertJsonStructure(['data' => ['took_ms']]);

    $elapsed = (microtime(true) - $start) * 1000;

    // سقف متساهل في CI؛ قياس P95 الحقيقي يتم عبر مشروع حقيقي (projects:import-demo + meilisearch:sync-settings)
    expect($elapsed)->toBeLessThan(5000);
});

it('filters across the full 1000-document set correctly', function () {
    $response = $this->getJson('/api/search?sector=health&score_min=70&status=needs_funding')
        ->assertStatus(200)
        ->assertJsonPath('meta.code', 'ok');

    // كل نتيجة يجب أن تحقق الفلاتر
    foreach ($response->json('data.hits') as $hit) {
        expect($hit['category'])->toBe('health');
        expect($hit['overall_score'])->toBeGreaterThanOrEqual(70);
        expect($hit['status'])->toBe('needs_funding');
    }
});

it('reports facet counts across the full set (FR-243)', function () {
    $this->getJson('/api/search')
        ->assertStatus(200)
        ->assertJsonPath('data.facets.sector.health', 500)
        ->assertJsonPath('data.facets.sector.fintech', 500)
        ->assertJsonPath('data.facets.tags.ai', 1000);
});

it('returns search results for a mixed Arabic query at volume', function () {
    $this->getJson('/api/search?q=تشخيص')
        ->assertStatus(200)
        ->assertJsonPath('meta.code', 'ok')
        ->assertJsonPath('data.pagination.total', 500)
        ->assertJsonCount(12, 'data.hits');
});
