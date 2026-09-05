<?php

namespace Tests\Feature\Search;

use App\Enums\EvaluationStatus;
use App\Models\Category;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);
uses(InteractsWithFakeSearch::class);

function sortProject(User $owner, Category $category, string $title, ?float $score, Carbon $createdAt, int $views): Project
{
    $project = Project::factory()->published()->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'title' => $title,
        'tags' => ['ai'],
        'view_count' => $views,
        'created_at' => $createdAt,
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

    // A: score 80 · قديم · 300 مشاهدة
    $this->a = sortProject($this->owner, $this->health, 'منصة تشخيص طبي', 80.0, now()->subDays(10), 300);

    // B: score 60 · متوسط · 100 مشاهدة
    $this->b = sortProject($this->owner, $this->health, 'تطبيق لياقة ذكي', 60.0, now()->subDays(5), 100);

    // C: بلا درجة · الأحدث · 500 مشاهدة
    $this->c = sortProject($this->owner, $this->health, 'تطبيق سياحة', null, now()->subDays(1), 500);
});

// ———————————————————————— T135 ————————————————————————

it('sorts by score desc with unrated projects last and keeps filters intact', function () {
    $this->getJson('/api/search?sector=health&sort=score&dir=desc')
        ->assertStatus(200)
        ->assertJsonPath('data.applied_filters.sector', 'health')
        ->assertJsonPath('data.hits.0.id', $this->a->id)
        ->assertJsonPath('data.hits.1.id', $this->b->id)
        ->assertJsonPath('data.hits.2.id', $this->c->id)
        ->assertJsonPath('data.hits.2.has_score', false);
});

it('sorts by score asc keeping unrated projects last', function () {
    $this->getJson('/api/search?sector=health&sort=score&dir=asc')
        ->assertStatus(200)
        ->assertJsonPath('data.hits.0.id', $this->b->id)
        ->assertJsonPath('data.hits.1.id', $this->a->id)
        ->assertJsonPath('data.hits.2.id', $this->c->id);
});

it('sorts by views_count desc', function () {
    $this->getJson('/api/search?sector=health&sort=views_count&dir=desc')
        ->assertStatus(200)
        ->assertJsonPath('data.hits.0.id', $this->c->id)
        ->assertJsonPath('data.hits.1.id', $this->a->id)
        ->assertJsonPath('data.hits.2.id', $this->b->id);
});

it('sorts by created_at desc (newest first)', function () {
    $this->getJson('/api/search?sector=health&sort=created_at&dir=desc')
        ->assertStatus(200)
        ->assertJsonPath('data.hits.0.id', $this->c->id)
        ->assertJsonPath('data.hits.1.id', $this->b->id)
        ->assertJsonPath('data.hits.2.id', $this->a->id);
});

it('sorts by created_at asc (oldest first)', function () {
    $this->getJson('/api/search?sector=health&sort=created_at&dir=asc')
        ->assertStatus(200)
        ->assertJsonPath('data.hits.0.id', $this->a->id)
        ->assertJsonPath('data.hits.1.id', $this->b->id)
        ->assertJsonPath('data.hits.2.id', $this->c->id);
});
