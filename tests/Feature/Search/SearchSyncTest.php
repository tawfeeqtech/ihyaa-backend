<?php

namespace Tests\Feature\Search;

use App\Enums\EvaluationStatus;
use App\Enums\ProjectStatus;
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
});

function indexedIds(): array
{
    return array_map(fn ($doc) => (int) $doc['id'], array_values(FakeSearchEngine::documents()));
}

// ———————————————————————— T126 ————————————————————————

it('indexes published projects on creation', function () {
    $project = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'category_id' => $this->health->id,
        'title' => 'مشروع منشور',
    ]);

    expect(FakeSearchEngine::documents()[(string) $project->id])->toHaveKey('title')
        ->and(FakeSearchEngine::documents()[(string) $project->id]['title'])->toBe('مشروع منشور');
});

it('does not index draft projects (FR-247)', function () {
    Project::factory()->draft()->create([
        'user_id' => $this->owner->id,
        'category_id' => $this->health->id,
        'title' => 'مسودة',
    ]);

    expect(indexedIds())->not->toContain(Project::where('title', 'مسودة')->value('id'));
});

it('reflects title edits in the index', function () {
    $project = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'category_id' => $this->health->id,
        'title' => 'العنوان القديم',
    ]);

    $project->update(['title' => 'العنوان الجديد']);

    expect(FakeSearchEngine::documents()[(string) $project->id]['title'])->toBe('العنوان الجديد');
});

it('removes a soft-deleted project from the index', function () {
    $project = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'category_id' => $this->health->id,
    ]);

    $project->delete();

    expect(FakeSearchEngine::documents())->not->toHaveKey((string) $project->id);
});

it('re-indexes a restored project', function () {
    $project = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'category_id' => $this->health->id,
    ]);

    $project->delete();
    expect(FakeSearchEngine::documents())->not->toHaveKey((string) $project->id);

    $project->restore();
    expect(FakeSearchEngine::documents())->toHaveKey((string) $project->id);
});

it('reflects an evaluation score change in the index', function () {
    $project = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'category_id' => $this->health->id,
    ]);

    expect(FakeSearchEngine::documents()[(string) $project->id]['has_score'])->toBeFalse();

    Evaluation::create([
        'project_id' => $project->id,
        'version' => 1,
        'status' => EvaluationStatus::COMPLETED,
        'overall_score' => 88.5,
        'confidence_score' => 80.0,
        'result' => ['dimensions' => [], 'overall_score' => 88.5],
        'model_used' => 'claude',
        'model_name' => 'claude-3-5-haiku',
        'provider_used' => 'claude',
        'completed_at' => now(),
    ]);

    $project->searchable(); // مستمع EvaluationCompleted يقوم بنفس الاستدعاء (US-034)

    expect(FakeSearchEngine::documents()[(string) $project->id]['has_score'])->toBeTrue()
        ->and(FakeSearchEngine::documents()[(string) $project->id]['overall_score'])->toBe(88.5);
});

it('does not index archived projects (FR-247)', function () {
    Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'category_id' => $this->health->id,
        'publication_status' => ProjectStatus::ARCHIVED,
        'title' => 'مؤرشف',
    ]);

    expect(indexedIds())->not->toContain(Project::where('title', 'مؤرشف')->value('id'));
});

// ———————————————————————— T129 ————————————————————————

it('search:rebuild indexes only published non-trashed projects', function () {
    Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'category_id' => $this->health->id,
        'title' => 'منشور 1',
    ]);
    Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'category_id' => $this->health->id,
        'title' => 'منشور 2',
    ]);
    Project::factory()->draft()->create([
        'user_id' => $this->owner->id,
        'category_id' => $this->health->id,
        'title' => 'مسودة',
    ]);
    $trashed = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'category_id' => $this->health->id,
        'title' => 'محذوف',
    ]);
    $trashed->delete();

    FakeSearchEngine::flushAll();
    expect(FakeSearchEngine::documents())->toBeEmpty();

    $this->artisan('search:rebuild')->assertSuccessful();

    $titles = collect(FakeSearchEngine::documents())->pluck('title')->all();
    expect($titles)->toContain('منشور 1')
        ->and($titles)->toContain('منشور 2')
        ->and($titles)->not->toContain('مسودة')
        ->and($titles)->not->toContain('محذوف');
});
