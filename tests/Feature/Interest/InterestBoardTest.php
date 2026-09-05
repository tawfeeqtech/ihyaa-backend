<?php

namespace Tests\Feature\Interest;

use App\Enums\InterestStatus;
use App\Models\Interest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * لوحتا الطلبات — SRS-API-23/24 · US-046 · contract §2/§3.
 * received (صاحب الفكرة): ترتيب DESC + فلترة status + عدّادات GROUP BY + ترقيم 12.
 * sent (المستثمر): can_cancel للطلبات النشطة + rejection_reason عند الرفض.
 */
beforeEach(function () {
    config(['scout.driver' => 'null']);
});

/**
 * ينشئ طلباً بحالة محددة من مستثمر مختلف + created_at محدد للترتيب القطعي.
 * ملاحظة: created_at/updated_at ليسا في $fillable — نضبطهما عبر forceFill بعد
 * الإنشاء (الترتيب DESC في اللوحة يحتاج تواريخ متميزة قابلة للتحديد).
 */
function boardInterest(Project $project, User $investor, InterestStatus $status, string $createdAt, ?string $reason = null): Interest
{
    $interest = Interest::create([
        'project_id' => $project->id,
        'investor_id' => $investor->id,
        'interest_type' => 'investment',
        'message' => null,
        'status' => $status,
        'rejection_reason' => $reason,
        'accepted_at' => $status === InterestStatus::ACCEPTED ? now() : null,
        'rejected_at' => $status === InterestStatus::REJECTED ? now() : null,
        'cancelled_at' => $status === InterestStatus::CANCELLED ? now() : null,
    ]);

    $interest->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ])->save();

    return $interest;
}

it('lists received interests for the idea owner with ordering, counters and no early email (US-046 · §2)', function () {
    $owner = User::factory()->ideaOwner()->create();
    $project = Project::factory()->published()->create(['user_id' => $owner->id]);
    $investors = User::factory()->count(5)->investor()->create();

    $i1 = boardInterest($project, $investors[0], InterestStatus::PENDING, '2026-08-01 10:00:00');
    $i2 = boardInterest($project, $investors[1], InterestStatus::PENDING, '2026-08-02 10:00:00');
    $i3 = boardInterest($project, $investors[2], InterestStatus::ACCEPTED, '2026-08-03 10:00:00');
    $i4 = boardInterest($project, $investors[3], InterestStatus::REJECTED, '2026-08-04 10:00:00', 'لا يناسب مجالي');
    $i5 = boardInterest($project, $investors[4], InterestStatus::CANCELLED, '2026-08-05 10:00:00');

    Sanctum::actingAs($owner);

    $this->getJson('/api/interests/received')
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(5, 'data')
        // عدّادات GROUP BY واحدة — إجمالي كل الحالات (لا تتأثر بالفلترة).
        ->assertJsonPath('counters.total', 5)
        ->assertJsonPath('counters.pending', 2)
        ->assertJsonPath('counters.accepted', 1)
        ->assertJsonPath('counters.rejected', 1)
        ->assertJsonPath('counters.cancelled', 1)
        // ترقيم افتراضي 12 (US-040).
        ->assertJsonPath('meta.per_page', 12)
        ->assertJsonPath('meta.total', 5)
        // ترتيب created_at DESC — الأحدث أولاً.
        ->assertJsonPath('data.0.id', $i5->id)
        ->assertJsonPath('data.1.id', $i4->id)
        ->assertJsonPath('data.4.id', $i1->id)
        // لا كشف مبكر — emails {} لغير المقبول، وللمقبول للطرف فقط.
        ->assertJsonPath('data.0.emails', [])
        ->assertJsonPath('data.1.emails', [])
        ->assertJsonPath('data.1.rejection_reason', 'لا يناسب مجالي')
        ->assertJsonPath('data.2.emails.investor_email', $investors[2]->email);
});

it('filters received interests by status and supports comma-combined values (US-046 · §2)', function () {
    $owner = User::factory()->ideaOwner()->create();
    $project = Project::factory()->published()->create(['user_id' => $owner->id]);
    $investors = User::factory()->count(5)->investor()->create();

    boardInterest($project, $investors[0], InterestStatus::PENDING, '2026-08-01 10:00:00');
    boardInterest($project, $investors[1], InterestStatus::PENDING, '2026-08-02 10:00:00');
    boardInterest($project, $investors[2], InterestStatus::ACCEPTED, '2026-08-03 10:00:00');
    boardInterest($project, $investors[3], InterestStatus::REJECTED, '2026-08-04 10:00:00');
    boardInterest($project, $investors[4], InterestStatus::CANCELLED, '2026-08-05 10:00:00');

    Sanctum::actingAs($owner);

    // pending,accepted → 3 طلبات
    $this->getJson('/api/interests/received?status=pending,accepted')
        ->assertStatus(200)
        ->assertJsonCount(3, 'data')
        // العدّادات دائماً على الكل — تحديث عند التحميل (لا تتأثر بالفلترة).
        ->assertJsonPath('counters.total', 5);

    // rejected فقط → 1
    $this->getJson('/api/interests/received?status=rejected')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'rejected')
        ->assertJsonPath('counters.total', 5);
});

it('paginates received interests with default 12 and honours per_page cap (US-040)', function () {
    $owner = User::factory()->ideaOwner()->create();
    $project = Project::factory()->published()->create(['user_id' => $owner->id]);
    $investors = User::factory()->count(13)->investor()->create();

    foreach ($investors as $i => $investor) {
        boardInterest($project, $investor, InterestStatus::PENDING, '2026-08-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT).' 10:00:00');
    }

    Sanctum::actingAs($owner);

    // per_page=5
    $this->getJson('/api/interests/received?per_page=5')
        ->assertStatus(200)
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.per_page', 5)
        ->assertJsonPath('meta.total', 13);

    // افتراضي 12 — الصفحة الثانية تحمل المتبقي
    $this->getJson('/api/interests/received')
        ->assertStatus(200)
        ->assertJsonCount(12, 'data')
        ->assertJsonPath('meta.per_page', 12);

    $this->getJson('/api/interests/received?page=2')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data');
});

it('lists sent interests for the investor with can_cancel and rejection_reason (US-046 · US-049 · §3)', function () {
    $investor = User::factory()->investor()->create();
    $owner = User::factory()->ideaOwner()->create();

    $p1 = Project::factory()->published()->create(['user_id' => $owner->id]);
    $p2 = Project::factory()->published()->create(['user_id' => $owner->id]);

    boardInterest($p1, $investor, InterestStatus::PENDING, '2026-08-01 10:00:00');
    boardInterest($p2, $investor, InterestStatus::REJECTED, '2026-08-02 10:00:00', 'لا يناسب مجالي');

    Sanctum::actingAs($investor);

    $this->getJson('/api/interests/sent')
        ->assertStatus(200)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('counters.total', 2)
        ->assertJsonPath('counters.pending', 1)
        ->assertJsonPath('counters.rejected', 1)
        // الأحدث أولاً — المرفوض في المقدمة (orderByDesc).
        ->assertJsonPath('data.0.status', 'rejected')
        ->assertJsonPath('data.0.rejection_reason', 'لا يناسب مجالي')
        // can_cancel فقط للطلبات النشطة — المرفوض لا يُلغى.
        ->assertJsonPath('data.0.can_cancel', false)
        ->assertJsonPath('data.1.status', 'pending')
        ->assertJsonPath('data.1.can_cancel', true)
        // لا كشف مبكر — emails {} لغير المقبول.
        ->assertJsonPath('data.0.emails', []);
});

it('forbids an investor from viewing the received board and an owner from the sent board (403)', function () {
    $investor = User::factory()->investor()->create();
    Sanctum::actingAs($investor);

    $this->getJson('/api/interests/received')
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    $owner = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($owner);

    $this->getJson('/api/interests/sent')
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});
