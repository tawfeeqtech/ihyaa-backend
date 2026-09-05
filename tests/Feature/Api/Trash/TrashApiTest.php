<?php

namespace Tests\Feature\Api\Trash;

use App\Enums\FileType;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * سلة المهملات عبر API — T071 · US-055 (trash-api.md §0..3).
 *
 * GET /trashed-projects (30/دقيقة) · POST /restore (10/دقيقة) · DELETE /force (10/دقيقة).
 * الترخيص: المالك فقط (403 FORBIDDEN) · 410 TRASH_EXPIRED بعد المهلة · confirm:true للحذف النهائي.
 */

beforeEach(function () {
    config(['scout.driver' => 'null']);
    Storage::fake('public');
    $this->owner = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($this->owner);
});

it('lists the owner trash with purge_at and restorable fields', function () {
    $trashed = Project::factory()->trashed()->create(['user_id' => $this->owner->id]);

    $this->getJson('/api/trashed-projects')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $trashed->id)
        ->assertJsonStructure([
            'data' => [[
                'id', 'title', 'deleted_at', 'restore_deadline', 'purge_at', 'days_remaining', 'restorable',
            ]],
        ]);
});

it('restores a trashed project (SRS-API-36)', function () {
    $trashed = Project::factory()->trashed()->create(['user_id' => $this->owner->id]);

    $this->postJson("/api/trashed-projects/{$trashed->id}/restore")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.restored', true);

    expect($trashed->fresh()->trashed())->toBeFalse();
});

it('rejects restoring beyond the 30-day window with 410 TRASH_EXPIRED', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');
    $trashed = Project::factory()->create(['user_id' => $this->owner->id, 'deleted_at' => now()->subDays(31)]);

    Carbon::setTestNow('2026-09-01 12:00:00');

    $this->postJson("/api/trashed-projects/{$trashed->id}/restore")
        ->assertStatus(410)
        ->assertJsonPath('code', 'TRASH_EXPIRED');
});

it('force deletes with confirm:true and removes disk files (SRS-API-37)', function () {
    $trashed = Project::factory()->trashed()->create(['user_id' => $this->owner->id]);
    Storage::disk('public')->put('files/p.png', 'content');
    ProjectFile::create([
        'project_id' => $trashed->id,
        'type' => FileType::IMAGE,
        'file_path' => 'files/p.png',
        'original_name' => 'p.png',
        'mime_type' => 'image/png',
        'file_size' => 7,
    ]);

    $this->deleteJson("/api/trashed-projects/{$trashed->id}/force", ['confirm' => true])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('projects', ['id' => $trashed->id]);
    $this->assertDatabaseMissing('project_files', ['project_id' => $trashed->id]);
    Storage::disk('public')->assertMissing('files/p.png');
});

it('blocks a non-owner from restoring another user trash with 403', function () {
    $other = User::factory()->ideaOwner()->create();
    $trashed = Project::factory()->trashed()->create(['user_id' => $other->id]);

    $this->postJson("/api/trashed-projects/{$trashed->id}/restore")
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('blocks an investor from the trash routes with 403 (idea-owner guard)', function () {
    Sanctum::actingAs(User::factory()->investor()->create());

    $this->getJson('/api/trashed-projects')->assertStatus(403);
});

it('requires authentication on the trash routes', function () {
    auth()->forgetGuards(); // يلغي Sanctum::actingAs من beforeEach

    $this->getJson('/api/trashed-projects')->assertStatus(401);
});
