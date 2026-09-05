<?php

namespace Tests\Feature\Api\Interests;

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
 * إلغاء طلب اهتمام — T087 · US-058 · UC-07 E2 (dashboard-api.md §3 · SRS-API-26).
 *
 * PUT /interests/{interest}/cancel — المستثمر المرسل فقط (Policy + آلة الحالات).
 * pending → cancelled (200) · غير pending → 409 · بعد القبول يُحذف ملف PDF وسجل
 * الاتفاق ويُخفى البريد · إشعار interest_cancelled للمالك · طلب جديد بعد الإلغاء مسموح.
 */
beforeEach(function () {
    config(['scout.driver' => 'null']);
    Storage::fake('public');

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $this->investor = User::factory()->investor()->create();
});

/** ينشئ طلب pending جاهز للإلغاء. */
function createCancellableInterest(Project $project, User $investor): Interest
{
    return Interest::create([
        'project_id' => $project->id,
        'investor_id' => $investor->id,
        'interest_type' => 'investment',
        'message' => 'طلب استثمار',
        'status' => InterestStatus::PENDING,
    ]);
}

it('cancels a pending interest, notifies the owner and allows a new request after (US-058 · T089)', function () {
    $interest = createCancellableInterest($this->project, $this->investor);
    Sanctum::actingAs($this->investor);

    $this->putJson("/api/interests/{$interest->id}/cancel")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'cancelled');

    $this->assertDatabaseHas('interests', [
        'id' => $interest->id,
        'status' => 'cancelled',
    ]);

    // إشعار غير حرج للمالك.
    $this->assertDatabaseHas('notifications', [
        'user_id' => $this->owner->id,
        'type' => 'interest_cancelled',
        'is_critical' => false,
    ]);

    // طلب جديد بعد الإلغاء مسموح (لم يعد الطلب الملغي "نشطاً").
    $this->postJson("/api/projects/{$this->project->id}/interest", [
        'interest_type' => 'investment',
        'message' => 'طلب جديد بعد الإلغاء',
    ])->assertStatus(201);
});

it('returns 409 INVALID_INTEREST_STATUS when cancelling a rejected interest (ERR-409-01)', function () {
    $interest = createCancellableInterest($this->project, $this->investor);
    $interest->reject('لا يناسبني');
    Sanctum::actingAs($this->investor);

    $this->putJson("/api/interests/{$interest->id}/cancel")
        ->assertStatus(409)
        ->assertJsonPath('code', 'INVALID_INTEREST_STATUS');
});

it('returns 409 INVALID_INTEREST_STATUS when cancelling an already-cancelled interest (UC-06 E3)', function () {
    // cancel() يستدعي آلة الحالات مباشرة (بلا assertPending) — cancelled حالة نهائية
    // → canTransition ترجع false → الكود الافتراضي INVALID_INTEREST_STATUS (لا تغيير على الدستور).
    $interest = createCancellableInterest($this->project, $this->investor);
    $interest->cancel();
    Sanctum::actingAs($this->investor);

    $this->putJson("/api/interests/{$interest->id}/cancel")
        ->assertStatus(409)
        ->assertJsonPath('code', 'INVALID_INTEREST_STATUS');
});

it('wins the cancel/accept race deterministically: the loser accept gets 409', function () {
    $interest = createCancellableInterest($this->project, $this->investor);

    // المستثمر يلغي أولاً (الفائز) ثم يحاول المالك القبول → 409.
    Sanctum::actingAs($this->investor);
    $this->putJson("/api/interests/{$interest->id}/cancel")->assertOk();

    Sanctum::actingAs($this->owner);
    $this->putJson("/api/interests/{$interest->id}/accept")
        ->assertStatus(409)
        ->assertJsonPath('code', 'INTEREST_CANCELLED');
});

it('reverts an accepted interest: deletes the agreement PDF and record, hides emails (UC-07 E2)', function () {
    $interest = createCancellableInterest($this->project, $this->investor);
    $interest->accept();

    $path = 'agreements/'.$interest->id.'.pdf';
    Storage::disk('public')->put($path, 'pdf-content');

    $agreement = Agreement::create([
        'interest_id' => $interest->id,
        'idea_owner_id' => $this->owner->id,
        'investor_id' => $this->investor->id,
        'project_id' => $this->project->id,
        'pdf_path' => $path,
        'idea_owner_name' => $this->owner->name,
        'investor_name' => $this->investor->name,
    ]);

    $interest->forceFill([
        'agreement_pdf_path' => $path,
        'agreement_id' => $agreement->id,
    ])->save();

    Sanctum::actingAs($this->investor);

    $this->putJson("/api/interests/{$interest->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    // ملف PDF محذوف + سجل الاتفاق محذوف + الحقول الصفرية.
    Storage::disk('public')->assertMissing($path);
    $this->assertDatabaseMissing('agreements', ['id' => $agreement->id]);
    $this->assertDatabaseHas('interests', [
        'id' => $interest->id,
        'agreement_pdf_path' => null,
        'agreement_id' => null,
    ]);
});

it('forbids a non-sender from cancelling (403 FORBIDDEN · InterestPolicy)', function () {
    $interest = createCancellableInterest($this->project, $this->investor);
    $other = User::factory()->investor()->create();
    Sanctum::actingAs($other);

    $this->putJson("/api/interests/{$interest->id}/cancel")
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    $this->assertDatabaseHas('interests', [
        'id' => $interest->id,
        'status' => 'pending',
    ]);
});
