<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    Project::factory()->count(25)->published()->create(['user_id' => $this->owner->id]);
});

it('paginates 25 projects as 12 per page with correct meta', function () {
    $this->getJson('/api/projects')
        ->assertStatus(200)
        ->assertJsonCount(12, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 12)
        ->assertJsonPath('meta.total', 25)
        ->assertJsonPath('meta.last_page', 3);
});

it('serves the remaining projects on later pages', function () {
    $this->getJson('/api/projects?page=2')
        ->assertStatus(200)
        ->assertJsonCount(12, 'data')
        ->assertJsonPath('meta.current_page', 2);

    $this->getJson('/api/projects?page=3')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.current_page', 3);
});

it('returns an empty data set for a page beyond the last', function () {
    $this->getJson('/api/projects?page=99')
        ->assertStatus(200)
        ->assertJsonCount(0, 'data');
});

it('respects a custom per_page parameter', function () {
    $this->getJson('/api/projects?per_page=5')
        ->assertStatus(200)
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.per_page', 5);
});

it('rejects per_page above the maximum of 12 (T156)', function () {
    $this->getJson('/api/projects?per_page=100')
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');
});

it('rejects page=0 with a validation error (gap: contract expects clamp to 1)', function () {
    $this->getJson('/api/projects?page=0')
        ->assertStatus(422)
        ->assertJsonValidationErrors('page');
});
