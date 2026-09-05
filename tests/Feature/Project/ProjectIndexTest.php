<?php

namespace Tests\Feature\Project;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
});

it('returns only published projects in the public gallery', function () {
    $published = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    Project::factory()->draft()->create(['user_id' => $this->owner->id]);
    Project::factory()->create([
        'user_id' => $this->owner->id,
        'publication_status' => ProjectStatus::ARCHIVED,
    ]);

    $this->getJson('/api/projects')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $published->id);
});

it('orders projects by newest created_at first', function () {
    $oldest = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'created_at' => now()->subDays(3),
    ]);
    $middle = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'created_at' => now()->subDays(2),
    ]);
    $newest = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'created_at' => now()->subDays(1),
    ]);

    $this->getJson('/api/projects')
        ->assertStatus(200)
        ->assertJsonPath('data.0.id', $newest->id)
        ->assertJsonPath('data.1.id', $middle->id)
        ->assertJsonPath('data.2.id', $oldest->id);
});

it('exposes evaluation_status completed when scored and pending otherwise (T148)', function () {
    $scored = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'ai_score' => 82,
        'created_at' => now()->subMinutes(2),
    ]);
    $pending = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'ai_score' => null,
        'created_at' => now()->subMinute(),
    ]);

    $this->getJson('/api/projects')
        ->assertStatus(200)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $pending->id)
        ->assertJsonPath('data.0.evaluation_status', 'pending')
        ->assertJsonPath('data.1.id', $scored->id)
        ->assertJsonPath('data.1.evaluation_status', 'completed');
});

it('excludes soft-deleted projects from the gallery', function () {
    $active = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $trashed = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $trashed->delete();

    $this->getJson('/api/projects')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $active->id);
});
