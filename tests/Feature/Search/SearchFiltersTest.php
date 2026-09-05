<?php

namespace Tests\Feature\Search;

use App\Enums\EvaluationStatus;
use App\Enums\ProjectState;
use App\Models\Category;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
uses(InteractsWithFakeSearch::class);

/**
 * مصنع مساعد: مشروع منشور بتصنيف/حالة/وسوم/درجة محددة.
 */
function makeScoredProject(User $owner, Category $category, string $state, array $tags, ?float $score): Project
{
    $project = Project::factory()->published()->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'status' => ProjectState::from($state),
        'tags' => $tags,
    ]);

    if ($score !== null) {
        Evaluation::create([
            'project_id' => $project->id,
            'version' => 1,
            'status' => EvaluationStatus::COMPLETED,
            'overall_score' => $score,
            'confidence_score' => 80.0,
            'result' => ['dimensions' => [], 'overall_score' => $score],
            'model_used' => 'claude',
            'model_name' => 'claude-3-5-haiku',
            'provider_used' => 'claude',
            'completed_at' => now(),
        ]);

        $project->update(['ai_score' => $score, 'last_evaluation_at' => now()]);
    }

    return $project;
}

beforeEach(function () {
    $this->useFakeSearchEngine();

    $this->owner = User::factory()->ideaOwner()->create();
    $this->health = Category::factory()->create(['slug' => 'health', 'name_ar' => 'الصحة']);
    $this->fintech = Category::factory()->create(['slug' => 'fintech', 'name_ar' => 'التقنية المالية']);

    // 1: health + needs_funding + [ai, health] + 80
    makeScoredProject($this->owner, $this->health, 'needs_funding', ['ai', 'health'], 80.0);
    // 2: health + completed + [ai] + 65
    makeScoredProject($this->owner, $this->health, 'completed', ['ai'], 65.0);
    // 3: health + needs_funding + [react] + 40
    makeScoredProject($this->owner, $this->health, 'needs_funding', ['react'], 40.0);
    // 4: fintech + needs_funding + [ai, fintech] + 90
    makeScoredProject($this->owner, $this->fintech, 'needs_funding', ['ai', 'fintech'], 90.0);
    // 5: fintech + needs_development + [saas] + بلا درجة
    makeScoredProject($this->owner, $this->fintech, 'needs_development', ['saas'], null);
});

// ———————————————————————— T121 ————————————————————————

it('intersects 4 AND filters correctly (sector+score+status+tags)', function () {
    $this->getJson('/api/search?sector=health&score_min=60&status=needs_funding&tags[]=ai')
        ->assertStatus(200)
        ->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.applied_filters.sector', 'health')
        ->assertJsonPath('data.applied_filters.score_min', 60)
        ->assertJsonPath('data.applied_filters.status', 'needs_funding')
        ->assertJsonPath('data.applied_filters.tags.0', 'ai');
});

it('combines score range with has_score=true filter', function () {
    $this->getJson('/api/search?sector=health&score_min=50&score_max=70')
        ->assertStatus(200)
        ->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.hits.0.overall_score', 65);
});

it('returns facet counts per option (FR-243)', function () {
    $this->getJson('/api/search?sector=health')
        ->assertStatus(200)
        ->assertJsonPath('data.pagination.total', 3)
        ->assertJsonPath('data.facets.sector.health', 3)
        ->assertJsonPath('data.facets.status.needs_funding', 2)
        ->assertJsonPath('data.facets.status.completed', 1)
        ->assertJsonPath('data.facets.tags.ai', 2)
        ->assertJsonPath('data.facets.tags.react', 1);
});

it('includes unscored projects when browsing without a score filter', function () {
    $this->getJson('/api/search?sector=fintech')
        ->assertStatus(200)
        ->assertJsonPath('data.pagination.total', 2)
        ->assertJsonCount(2, 'data.hits');
});

it('sanitizes invalid values: score_min=999 clamps to 100, sort=evil falls back to default', function () {
    $this->getJson('/api/search?score_min=999&sort=evil&dir=sideways')
        ->assertStatus(200)
        ->assertJsonPath('data.applied_filters.score_min', 100)
        ->assertJsonPath('data.applied_filters.sort', 'score')
        ->assertJsonPath('data.applied_filters.dir', 'desc');
});

it('ignores invalid status and tag values safely (no 500)', function () {
    $this->getJson('/api/search?status=bogus&tags[]=bad-tag<script>&tags[]=ai')
        ->assertStatus(200)
        ->assertJsonMissingPath('data.applied_filters.status')
        ->assertJsonPath('data.applied_filters.tags.0', 'ai')
        ->assertJsonCount(1, 'data.applied_filters.tags');
});
