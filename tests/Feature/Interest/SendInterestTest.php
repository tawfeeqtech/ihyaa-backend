<?php

namespace Tests\Feature\Interest;

use App\Enums\UserRole;
use App\Models\Interest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * إرسال طلب اهتمام — SRS-API-22 · US-041/042/043 · contract §1.
 * 201 نجاح + إشعار حرج interest_new · 401 · 422 self_interest · 422
 * project_unavailable (مشروع محذوف) · 422 profile_incomplete (ملف ناقص).
 */
beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $this->investor = User::factory()->investor()->create();
});

it('sends an interest request and fires a critical interest_new notification (US-041 · T033)', function () {
    Sanctum::actingAs($this->investor);

    $this->postJson("/api/projects/{$this->project->id}/interest", [
        'interest_type' => 'investment',
        'message' => 'أرى في مشروعك فرصة واعدة',
    ])
        ->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.interest_type', 'investment')
        ->assertJsonPath('data.message', 'أرى في مشروعك فرصة واعدة')
        // لا كشف مبكر للبريد — دستور §I: كائن فارغ {} حتى القبول.
        ->assertJsonPath('data.emails', []);

    $this->assertDatabaseHas('interests', [
        'project_id' => $this->project->id,
        'investor_id' => $this->investor->id,
        'status' => 'pending',
    ]);

    // إشعار حرج (is_critical = 1) — interest_new · contract §1 (يُبث عبر Reverb).
    $this->assertDatabaseHas('notifications', [
        'user_id' => $this->owner->id,
        'type' => 'interest_new',
        'is_critical' => true,
    ]);
});

it('requires authentication to send interest (401)', function () {
    $this->postJson("/api/projects/{$this->project->id}/interest", [
        'interest_type' => 'investment',
    ])
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('rejects interest in your own project (422 self_interest · contract §1)', function () {
    $ownProject = Project::factory()->published()->create(['user_id' => $this->investor->id]);
    Sanctum::actingAs($this->investor);

    $this->postJson("/api/projects/{$ownProject->id}/interest", [
        'interest_type' => 'investment',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'self_interest');

    $this->assertDatabaseCount('interests', 0);
});

it('returns 422 project_unavailable for a trashed project (UC-06 E2 · contract §1)', function () {
    $trashed = Project::factory()->trashed()->create(['user_id' => $this->owner->id]);
    Sanctum::actingAs($this->investor);

    // withTrashed على المسار — يصل المشروع المحذوف إلى الخدمة فتفحصه (لا 404).
    $this->postJson("/api/projects/{$trashed->id}/interest", [
        'interest_type' => 'investment',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'project_unavailable');

    $this->assertDatabaseCount('interests', 0);
});

it('returns 422 profile_incomplete when investor profile is missing required fields (UC-06 A1)', function () {
    $incomplete = User::factory()->create([
        'role' => UserRole::INVESTOR,
        'investment_focus' => null,
        'preferred_sectors' => null,
    ]);
    Sanctum::actingAs($incomplete);

    $this->postJson("/api/projects/{$this->project->id}/interest", [
        'interest_type' => 'investment',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'profile_incomplete')
        ->assertJsonPath('redirect', '/profile/edit');

    $this->assertDatabaseCount('interests', 0);
});

it('returns 422 VALIDATION_FAILED when interest_type is missing or invalid', function () {
    Sanctum::actingAs($this->investor);

    $this->postJson("/api/projects/{$this->project->id}/interest", [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    $this->postJson("/api/projects/{$this->project->id}/interest", [
        'interest_type' => 'not-a-valid-type',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');
});

it('does not reveal the investor email before acceptance (دستور §I)', function () {
    Sanctum::actingAs($this->investor);

    $this->postJson("/api/projects/{$this->project->id}/interest", [
        'interest_type' => 'consultation',
    ])
        ->assertStatus(201)
        ->assertJsonPath('data.emails', [])
        ->assertJsonMissingPath('data.emails.investor_email');
});
