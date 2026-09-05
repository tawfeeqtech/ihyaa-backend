<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($this->owner);

    $this->project = Project::factory()->trashed()->create([
        'user_id' => $this->owner->id,
    ]);
});

it('force deletes a trashed project with confirm:true (T136)', function () {
    $this->deleteJson("/api/trashed-projects/{$this->project->id}/force", ['confirm' => true])
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', __('trash.force_deleted'));

    $this->assertDatabaseMissing('projects', ['id' => $this->project->id]);
});

it('rejects force delete without confirm (T136)', function () {
    $this->deleteJson("/api/trashed-projects/{$this->project->id}/force")
        ->assertStatus(422)
        ->assertJsonPath('code', 'CONFIRMATION_REQUIRED')
        ->assertJsonPath('message', __('trash.confirm_required'))
        ->assertJsonPath('errors.confirm.0', 'مطلوب true');

    // المشروع لم يُحذف نهائياً — ما زال في سلة المهملات
    $this->assertSoftDeleted('projects', ['id' => $this->project->id]);
});

it('rejects force delete with confirm:false (T136)', function () {
    $this->deleteJson("/api/trashed-projects/{$this->project->id}/force", ['confirm' => false])
        ->assertStatus(422)
        ->assertJsonPath('code', 'CONFIRMATION_REQUIRED');

    $this->assertSoftDeleted('projects', ['id' => $this->project->id]);
});
