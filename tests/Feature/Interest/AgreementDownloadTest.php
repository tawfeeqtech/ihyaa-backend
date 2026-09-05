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
 * تنزيل مستند الاتفاق — SRS-API-27 · US-045 · contract §5.
 *
 * الغلاف: الطرفان فقط + الأدمن (AgreementAccessGuard). يُمرَّر {agreement} برقم
 * سجل الاتفاق (agreements.id) — يطابق pdf_url الصادر من InterestResource/لوحة
 * المستثمر. هذه الاختبارات ترصد إصلاحين: (1) 500 عند حذف المشروع ناعماً،
 * (2) خلل المطابقة حين يختلف agreements.id عن interests.id.
 */
beforeEach(function () {
    config(['scout.driver' => 'null']);
    Storage::fake('public');

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $this->investor = User::factory()->investor()->create();
});

/** ينشئ طلب pending ويقبله — يُرجع سجل الاتفاق (مع ملف PDF على القرص الوهمي). */
function createAcceptedAgreement(Project $project, User $owner, User $investor): Agreement
{
    $interest = Interest::create([
        'project_id' => $project->id,
        'investor_id' => $investor->id,
        'interest_type' => 'investment',
        'status' => InterestStatus::PENDING,
    ]);
    Sanctum::actingAs($owner);
    test()->putJson("/api/interests/{$interest->id}/accept")->assertStatus(200);

    return Agreement::where('interest_id', $interest->id)->firstOrFail();
}

it('lets both parties download the agreement PDF (US-045)', function () {
    $agreement = createAcceptedAgreement($this->project, $this->owner, $this->investor);

    Sanctum::actingAs($this->owner);
    $this->getJson("/api/agreements/{$agreement->id}")
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');

    Sanctum::actingAs($this->investor);
    $this->getJson("/api/agreements/{$agreement->id}")
        ->assertStatus(200);
});

it('forbids a third party with 403 FORBIDDEN', function () {
    $agreement = createAcceptedAgreement($this->project, $this->owner, $this->investor);

    $outsider = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($outsider);

    $this->getJson("/api/agreements/{$agreement->id}")
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('still serves the PDF after the project is soft-deleted (regression: was 500)', function () {
    $agreement = createAcceptedAgreement($this->project, $this->owner, $this->investor);

    $this->deleteJson("/api/projects/{$this->project->id}")->assertStatus(200);

    Sanctum::actingAs($this->owner);
    $this->getJson("/api/agreements/{$agreement->id}")
        ->assertStatus(200);
});

it('resolves the agreement record even when agreement id differs from interest id (regression)', function () {
    // إنشاء اتفاقية أخرى أولاً يجعل agreements.id يختلف عن interests.id —
    // كانت الواجهة تربط {agreement} بجدول interests خطأً (سجل خاطئ/403).
    $otherOwner = User::factory()->ideaOwner()->create();
    $otherProject = Project::factory()->published()->create(['user_id' => $otherOwner->id]);
    $otherInvestor = User::factory()->investor()->create();
    createAcceptedAgreement($otherProject, $otherOwner, $otherInvestor);

    $agreement = createAcceptedAgreement($this->project, $this->owner, $this->investor);

    Sanctum::actingAs($this->investor);
    $this->getJson("/api/agreements/{$agreement->id}")
        ->assertStatus(200);
});

it('returns 404 NOT_FOUND when the pdf file is missing', function () {
    $agreement = createAcceptedAgreement($this->project, $this->owner, $this->investor);
    Storage::disk('public')->delete((string) $agreement->pdf_path);

    Sanctum::actingAs($this->owner);
    $this->getJson("/api/agreements/{$agreement->id}")
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');
});

it('returns 404 NOT_FOUND for a non-existent agreement id', function () {
    Sanctum::actingAs($this->owner);
    $this->getJson('/api/agreements/99999')
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');
});
