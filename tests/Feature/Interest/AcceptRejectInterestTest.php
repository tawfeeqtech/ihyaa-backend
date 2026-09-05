<?php

namespace Tests\Feature\Interest;

use App\Enums\InterestStatus;
use App\Models\Agreement;
use App\Models\Interest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * قبول/رفض طلب اهتمام — SRS-API-26/27 · US-044/045 · contract §5/§6.
 * قبول → status=accepted + إنشاء PDF/اتفاق + كشف البريد + إشعار · رفض → سبب ·
 * غير-pending → 409 · غير مالك → 403.
 */
beforeEach(function () {
    config(['scout.driver' => 'null']);
    Storage::fake('public');

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $this->investor = User::factory()->investor()->create();
});

/** ينشئ طلب pending جاهز للمعالجة. */
function createPendingInterest(Project $project, User $investor): Interest
{
    return Interest::create([
        'project_id' => $project->id,
        'investor_id' => $investor->id,
        'interest_type' => 'investment',
        'message' => 'طلب استثمار',
        'status' => InterestStatus::PENDING,
    ]);
}

it('accepts a pending interest, creates the agreement PDF and reveals emails (US-044/045)', function () {
    $interest = createPendingInterest($this->project, $this->investor);
    Sanctum::actingAs($this->owner);

    $this->putJson("/api/interests/{$interest->id}/accept")
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.agreement_id', fn ($v) => $v !== null)
        ->assertJsonPath('data.agreement.pdf_url', fn ($v) => str_starts_with((string) $v, '/api/agreements/'))
        // كشف البريد بعد القبول — للطرف (صاحب الفكرة) فقط (دستور §I/§V).
        ->assertJsonPath('data.emails.investor_email', $this->investor->email)
        ->assertJsonPath('data.emails.idea_owner_email', $this->owner->email);

    $this->assertDatabaseHas('interests', [
        'id' => $interest->id,
        'status' => 'accepted',
    ]);

    $this->assertDatabaseHas('agreements', [
        'interest_id' => $interest->id,
    ]);

    // إشعار غير حرج للمستثمر عند نجاح PDF.
    $this->assertDatabaseHas('notifications', [
        'user_id' => $this->investor->id,
        'type' => 'interest_accepted',
        'is_critical' => false,
    ]);

    // ملف PDF على القرص المحلي.
    Storage::disk('public')->assertExists((string) $interest->fresh()->agreement_pdf_path);
});

it('rejects a pending interest with a reason (US-044 · contract §6)', function () {
    $interest = createPendingInterest($this->project, $this->investor);
    Sanctum::actingAs($this->owner);

    $this->putJson("/api/interests/{$interest->id}/reject", [
        'rejection_reason' => 'المشروع لا يناسب مجال استثماري حالياً',
    ])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejection_reason', 'المشروع لا يناسب مجال استثماري حالياً');

    $this->assertDatabaseHas('interests', [
        'id' => $interest->id,
        'status' => 'rejected',
        'rejection_reason' => 'المشروع لا يناسب مجال استثماري حالياً',
    ]);

    $this->assertDatabaseHas('notifications', [
        'user_id' => $this->investor->id,
        'type' => 'interest_rejected',
    ]);
});

it('returns 409 INTEREST_CANCELLED when acting on a cancelled interest (UC-06 E3)', function () {
    $interest = createPendingInterest($this->project, $this->investor);
    $interest->cancel();
    Sanctum::actingAs($this->owner);

    $this->putJson("/api/interests/{$interest->id}/accept")
        ->assertStatus(409)
        ->assertJsonPath('code', 'INTEREST_CANCELLED');
});

it('returns 409 INVALID_INTEREST_STATUS when re-accepting an accepted interest (US-044)', function () {
    $interest = createPendingInterest($this->project, $this->investor);
    $interest->accept();
    Sanctum::actingAs($this->owner);

    $this->putJson("/api/interests/{$interest->id}/accept")
        ->assertStatus(409)
        ->assertJsonPath('code', 'INVALID_INTEREST_STATUS');
});

it('forbids a non-owner from accepting (403 · InterestPolicy)', function () {
    $interest = createPendingInterest($this->project, $this->investor);
    $other = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($other);

    $this->putJson("/api/interests/{$interest->id}/accept")
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});
