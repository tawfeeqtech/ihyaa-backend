<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);

    Sanctum::actingAs($this->owner);
});

it('soft-deletes a project and hides it from public listing and detail', function () {
    $this->deleteJson("/api/projects/{$this->project->id}")
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->assertSoftDeleted('projects', ['id' => $this->project->id]);

    $this->getJson("/api/projects/{$this->project->id}")->assertStatus(404);

    $this->getJson('/api/projects')
        ->assertStatus(200)
        ->assertJsonCount(0, 'data');
});

it('lists trashed projects with the remaining recovery days', function () {
    $this->project->delete();

    $this->getJson('/api/trashed-projects')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->project->id)
        ->assertJsonStructure(['data' => [['id', 'title', 'deleted_at', 'restore_deadline', 'days_remaining']]]);
});

it('restores a trashed project within the recovery window', function () {
    $this->project->delete();

    $this->postJson("/api/trashed-projects/{$this->project->id}/restore")
        ->assertStatus(200)
        ->assertJsonPath('data.restored', true);

    $this->assertNotSoftDeleted('projects', ['id' => $this->project->id]);

    $this->getJson("/api/projects/{$this->project->id}")->assertStatus(200);
});

it('refuses to restore a project that is not trashed', function () {
    $this->postJson("/api/trashed-projects/{$this->project->id}/restore")
        ->assertStatus(422)
        ->assertJsonPath('code', 'PROJECT_NOT_TRASHED');
});

it('forbids a non-owner from restoring or force deleting', function () {
    $other = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($other);
    $this->project->delete();

    $this->postJson("/api/trashed-projects/{$this->project->id}/restore")
        ->assertStatus(403);

    $this->deleteJson("/api/trashed-projects/{$this->project->id}/force", ['confirm' => true])
        ->assertStatus(403);
});
