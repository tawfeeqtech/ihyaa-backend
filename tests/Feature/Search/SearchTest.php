<?php

namespace Tests\Feature\Search;

use App\Enums\EvaluationStatus;
use App\Models\Category;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
uses(InteractsWithFakeSearch::class);

beforeEach(function () {
    $this->useFakeSearchEngine();

    $this->owner = User::factory()->ideaOwner()->create();
    $this->health = Category::factory()->create(['slug' => 'health', 'name_ar' => 'الصحة']);
    $this->fintech = Category::factory()->create(['slug' => 'fintech', 'name_ar' => 'التقنية المالية']);

    $this->scored = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'category_id' => $this->health->id,
        'title' => 'منصة تشخيص طبي بالذكاء الاصطناعي',
        'description' => 'نظام يحلل الأشعة الطبية ويقدم توصيات دقيقة.',
        'tags' => ['ai', 'health'],
        'view_count' => 100,
    ]);

    $this->unscored = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'category_id' => $this->fintech->id,
        'title' => 'منصة تمويل المشاريع الناشئة',
        'description' => 'تربط رواد الأعمال بالمستثمرين.',
        'tags' => ['fintech', 'saas'],
        'view_count' => 5,
    ]);

    Evaluation::create([
        'project_id' => $this->scored->id,
        'version' => 1,
        'status' => EvaluationStatus::COMPLETED,
        'overall_score' => 72.4,
        'confidence_score' => 80.0,
        'result' => ['dimensions' => [], 'overall_score' => 72.4],
        'model_used' => 'claude',
        'model_name' => 'claude-3-5-haiku',
        'provider_used' => 'claude',
        'completed_at' => now(),
    ]);

    $this->scored->update(['ai_score' => 72.4, 'last_evaluation_at' => now()]);
});

// ———————————————————————— T114 ————————————————————————

it('returns ranked hits, facets, applied_filters and permalinks', function () {
    $this->getJson('/api/search')
        ->assertStatus(200)
        ->assertJsonPath('meta.code', 'ok')
        ->assertJsonPath('data.pagination.total', 2)
        ->assertJsonCount(2, 'data.hits')
        ->assertJsonStructure([
            'data' => [
                'hits' => [[
                    'id', 'title', 'description_snippet', 'category', 'tags', 'status',
                    'overall_score', 'has_score', 'views_count', 'created_at',
                    'cover_image_url', '_formatted',
                ]],
                'pagination' => ['page', 'per_page', 'total', 'total_pages'],
                'facets' => ['sector', 'status', 'tags'],
                'applied_filters' => ['sort', 'dir'],
                'took_ms',
                'permalinks' => ['self', 'ui'],
            ],
        ])
        ->assertJsonPath('data.facets.sector.health', 1)
        ->assertJsonPath('data.facets.sector.fintech', 1)
        ->assertJsonPath('data.facets.tags.ai', 1)
        ->assertJsonPath('data.applied_filters.sort', 'score')
        ->assertJsonPath('data.applied_filters.dir', 'desc')
        ->assertJsonPath('data.permalinks.ui', '/search?sort=score&dir=desc&page=1');
});

it('ranks scored projects first on default score sort (unrated last)', function () {
    $this->getJson('/api/search')
        ->assertStatus(200)
        ->assertJsonPath('data.hits.0.id', $this->scored->id)
        ->assertJsonPath('data.hits.0.overall_score', 72.4)
        ->assertJsonPath('data.hits.0.has_score', true)
        ->assertJsonPath('data.hits.1.id', $this->unscored->id)
        ->assertJsonPath('data.hits.1.has_score', false);
});

it('returns an empty state with suggestions and browse-all action', function () {
    $this->getJson('/api/search?q=zzzzzz-not-found')
        ->assertStatus(200)
        ->assertJsonPath('meta.code', 'empty')
        ->assertJsonPath('data.pagination.total', 0)
        ->assertJsonCount(0, 'data.hits')
        ->assertJsonCount(2, 'suggestions')
        ->assertJsonPath('actions.browse_all_url', '/api/search?sort=score&dir=desc');
});

it('returns 503 SEARCH_UNAVAILABLE (retryable) when Meilisearch is down', function () {
    FakeSearchEngine::failNextSearch();

    $this->getJson('/api/search')
        ->assertStatus(503)
        ->assertJsonPath('code', 'SEARCH_UNAVAILABLE')
        ->assertJsonPath('retryable', true)
        ->assertJsonPath('errors', []);
});

it('truncates a query longer than 100 characters', function () {
    $long = str_repeat('أ', 250);

    $this->getJson('/api/search?q='.$long)
        ->assertStatus(200)
        ->assertJsonPath('data.applied_filters.q', mb_substr($long, 0, 100));
});
