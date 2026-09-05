<?php

namespace Tests\Feature\Api\SavedProjects;

use App\Models\Project;
use App\Models\SavedProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * المشاريع المحفوظة — T092 · US-059 (saved-projects-api.md §5 · SRS-API-32/33/34).
 *
 * POST/DELETE /projects/{project}/save · GET /saved-projects — Investor فقط.
 * Idempotent: حفظ مكرر → 200 · إزالة غير موجودة → 200 removed:false ·
 * مشروع soft-deleted لا يُحفظ (404) لكن يبقى في القائمة available:false.
 */
beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $this->investor = User::factory()->investor()->create();
    Sanctum::actingAs($this->investor);
});

it('saves a new project with 201 and creates a row (contract §5/1)', function () {
    $this->postJson("/api/projects/{$this->project->id}/save")
        ->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.saved', true)
        ->assertJsonPath('data.already_saved', false)
        ->assertJsonPath('data.saved_id', fn ($id) => is_int($id));

    $this->assertDatabaseHas('saved_projects', [
        'user_id' => $this->investor->id,
        'project_id' => $this->project->id,
    ]);
});

it('is idempotent: a duplicate save returns 200 with a single row (contract §5/2)', function () {
    $this->postJson("/api/projects/{$this->project->id}/save")->assertStatus(201);

    $this->postJson("/api/projects/{$this->project->id}/save")
        ->assertStatus(200)
        ->assertJsonPath('data.saved', true)
        ->assertJsonPath('data.already_saved', true);

    $this->assertDatabaseCount('saved_projects', 1);
});

it('survives a concurrent save race with a single row (contract §5/3)', function () {
    // النتيجة القابلة للملاحظة للسباق: الحفظ الثاني ينجح (200) ولا يتكرر الصف —
    // يضمنها firstOrCreate + القيد الفريد user_id+project_id (الالتقاط 23000 دفاعي).
    $service = app(\App\Services\Saved\SavedProjectService::class);
    $service->save($this->investor, $this->project);
    $second = $service->save($this->investor, $this->project);

    expect($second['already_saved'])->toBeTrue();
    expect($this->investor->savedProjects()->count())->toBe(1);
});

it('removes a saved project with 200 and deleted row (contract §5/4)', function () {
    $saved = $this->investor->savedProjects()->create(['project_id' => $this->project->id]);

    $this->deleteJson("/api/projects/{$this->project->id}/save")
        ->assertOk()
        ->assertJsonPath('data.saved', false)
        ->assertJsonPath('data.removed', true);

    $this->assertDatabaseMissing('saved_projects', ['id' => $saved->id]);
});

it('returns 200 removed:false when the project was not saved (idempotent — contract §5/5)', function () {
    $this->deleteJson("/api/projects/{$this->project->id}/save")
        ->assertOk()
        ->assertJsonPath('data.saved', false)
        ->assertJsonPath('data.removed', false);
});

it('refuses to save a soft-deleted project with 404 (contract §5/6)', function () {
    $trashed = Project::factory()->trashed()->create(['user_id' => $this->owner->id]);

    $this->postJson("/api/projects/{$trashed->id}/save")
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');

    $this->assertDatabaseCount('saved_projects', 0);
});

it('blocks an idea owner from saving with 403 (contract §5/7)', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson("/api/projects/{$this->project->id}/save")
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('lists saved projects and flags soft-deleted ones as unavailable (contract §5/8)', function () {
    $kept = $this->project;
    $trashedProject = Project::factory()->published()->create(['user_id' => $this->owner->id]);

    $this->investor->savedProjects()->create(['project_id' => $kept->id]);
    $this->investor->savedProjects()->create(['project_id' => $trashedProject->id]);

    // المشروع المحفوظ يُحذف soft — يبقى في القائمة لكن available:false.
    $trashedProject->delete();

    $this->getJson('/api/saved-projects')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2);

    $byProject = collect($this->getJson('/api/saved-projects')->json('data'))->keyBy('project.id');

    expect($byProject[$kept->id]['project']['available'])->toBeTrue();
    expect($byProject[$trashedProject->id]['project']['available'])->toBeFalse();
});
