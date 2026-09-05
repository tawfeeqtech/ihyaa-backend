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

function permalinkProject(User $owner, Category $category, string $title, ?float $score): Project
{
    $project = Project::factory()->published()->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'title' => $title,
        'tags' => ['ai'],
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

    $this->top = permalinkProject($this->owner, $this->health, 'منصة تشخيص طبي', 90.0);
    $this->mid = permalinkProject($this->owner, $this->health, 'تطبيق لياقة', 70.0);
    $this->low = permalinkProject($this->owner, $this->fintech, 'منصة تمويل', 50.0);
});

// ———————————————————————— T139 ————————————————————————

it('rebuilds the identical state from a permalink (same hits + filters + sort)', function () {
    $first = $this->getJson('/api/search?sector=health&score_min=60&sort=score&dir=desc&page=1')
        ->assertStatus(200)
        ->assertJsonPath('data.pagination.total', 2);

    $permalink = $first->json('data.permalinks.self');

    $this->assertIsString($permalink);
    $this->assertStringStartsWith('/api/search', $permalink);

    $rebuilt = $this->getJson($permalink)
        ->assertStatus(200)
        ->assertJsonPath('data.pagination.total', 2);

    expect($rebuilt->json('data.hits'))->toEqual($first->json('data.hits'))
        ->and($rebuilt->json('data.applied_filters'))->toEqual($first->json('data.applied_filters'));
});

it('exposes the UI permalink route', function () {
    $this->getJson('/api/search?sector=health')
        ->assertStatus(200)
        ->assertJsonPath('data.permalinks.ui', '/search?sector=health&sort=score&dir=desc&page=1');
});

it('ignores invalid params safely and returns the default state', function () {
    $this->getJson('/api/search?sort=evil&dir=up&score_min=abc&status=bogus&page=-5&per_page=999')
        ->assertStatus(200)
        ->assertJsonPath('meta.code', 'ok')
        ->assertJsonPath('data.applied_filters.sort', 'score')
        ->assertJsonPath('data.applied_filters.dir', 'desc')
        ->assertJsonMissingPath('data.applied_filters.score_min')
        ->assertJsonMissingPath('data.applied_filters.status')
        ->assertJsonPath('data.pagination.page', 1)
        ->assertJsonPath('data.pagination.per_page', 24);
});
