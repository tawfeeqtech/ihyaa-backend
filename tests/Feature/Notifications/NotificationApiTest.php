<?php

namespace Tests\Feature\Notifications;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * واجهة الإشعارات — SRS-API-28..31 · RL-SH-05..08 · EPIC-09 (US-047 · T065).
 *
 * الجدول هو مصدر الحقيقة الوحيد (US-049): قائمة 20/صفحة + unread_count في الهامش،
 * آخر 5 للجرس، قراءة مفردة idempotent + 403 لغير المالك، قراءة الكل، عدّاد غير المقروء.
 */
beforeEach(function () {
    config(['scout.driver' => 'null']);
});

/**
 * ينشئ إشعاراً بمحتوى وقراءة وتاريخ محدد — للترتيب القطعي (created_at DESC).
 */
function makeNotification(User $user, string $type, string $title, bool $read = false, string $createdAt = '2026-08-01 10:00:00', array $data = []): Notification
{
    $notification = Notification::create([
        'user_id' => $user->id,
        'type' => $type,
        'title' => $title,
        'body' => 'نص تجريبي',
        'data' => $data,
        'is_critical' => false,
        'read_at' => $read ? now() : null,
    ]);

    $notification->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ])->save();

    return $notification;
}

it('lists notifications paginated with 20 per page and unread_count in meta (US-047 · T065)', function () {
    $user = User::factory()->ideaOwner()->create();

    foreach (range(1, 25) as $i) {
        makeNotification($user, 'interest_accepted', "إشعار $i", false, '2026-08-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT).' 10:00:00');
    }

    Sanctum::actingAs($user);

    $this->getJson('/api/notifications')
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.per_page', 20)
        ->assertJsonPath('meta.total', 25)
        // unread_count في الهامش (RL-SH-08) — الكل غير مقروء هنا.
        ->assertJsonPath('meta.unread_count', 25)
        // ترتيب created_at DESC.
        ->assertJsonPath('data.0.title', 'إشعار 25');
});

it('paginates the second page and caps per_page at 50 (US-047 · RL-SH-05)', function () {
    $user = User::factory()->investor()->create();

    foreach (range(1, 60) as $i) {
        makeNotification($user, 'interest_cancelled', "ن $i", false, '2026-08-01 10:00:00');
    }

    Sanctum::actingAs($user);

    // per_page=100 → يُقيَّد إلى 50 (السقف الأعلى).
    $this->getJson('/api/notifications?per_page=100')
        ->assertStatus(200)
        ->assertJsonCount(50, 'data')
        ->assertJsonPath('meta.per_page', 50);

    // الصفحة الثانية تحمل الباقي.
    $this->getJson('/api/notifications?page=2&per_page=50')
        ->assertStatus(200)
        ->assertJsonCount(10, 'data');
});

it('returns the latest 5 notifications for the bell with top-level unread_count (US-047 · T069)', function () {
    $user = User::factory()->ideaOwner()->create();

    foreach (range(1, 8) as $i) {
        makeNotification($user, 'interest_new', "حرج $i", false, '2026-08-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).' 10:00:00');
    }

    Sanctum::actingAs($user);

    $this->getJson('/api/notifications/recent')
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('unread_count', 8)
        // الأحدث أولاً — آخر 5 فقط (1..8 → 8,7,6,5,4).
        ->assertJsonPath('data.0.title', 'حرج 8')
        ->assertJsonPath('data.4.title', 'حرج 4')
        ->assertJsonMissingPath('data.5.title');
});

it('marks a single notification as read idempotently and includes url from data (US-047 · T067)', function () {
    $user = User::factory()->ideaOwner()->create();
    $notification = makeNotification($user, 'interest_new', 'طلب اهتمام جديد', false, '2026-08-01 10:00:00', ['url' => '/projects/5', 'project_id' => 5]);

    Sanctum::actingAs($user);

    // التأكد من وجود url في المورد (تنقّل الجرس المنسدل — T069).
    $this->getJson('/api/notifications/recent')
        ->assertJsonPath('data.0.url', '/projects/5')
        ->assertJsonPath('data.0.type', 'interest_new');

    $first = $this->putJson("/api/notifications/{$notification->id}/read")
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $notification->id);

    $this->assertNotNull($first->json('data.read_at'));

    // Idempotent — قراءة ثانية تُرجع نفس read_at ولا تُحدّث شيئاً.
    $this->putJson("/api/notifications/{$notification->id}/read")
        ->assertStatus(200)
        ->assertJsonPath('data.read_at', $first->json('data.read_at'));

    // unread_count انخفض إلى 0 بعد القراءة — داخل data (الحمولة الطبيعية للمورد).
    $this->getJson('/api/notifications/unread-count')
        ->assertStatus(200)
        ->assertJsonPath('data.unread_count', 0);
});

it('forbids marking a notification read by a non-owner (403 FORBIDDEN · دستور V)', function () {
    $owner = User::factory()->ideaOwner()->create();
    $other = User::factory()->investor()->create();
    $notification = makeNotification($owner, 'interest_new', 'طلب اهتمام', false);

    Sanctum::actingAs($other);

    $this->putJson("/api/notifications/{$notification->id}/read")
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    // لم تتغير القراءة (لا كشف ولا تعديل عبر المالك).
    $this->assertDatabaseHas('notifications', [
        'id' => $notification->id,
        'read_at' => null,
    ]);
});

it('marks all notifications as read and returns marked count with unread_count zero (US-047 · T067)', function () {
    $user = User::factory()->ideaOwner()->create();

    makeNotification($user, 'interest_accepted', 'مقبول 1');
    makeNotification($user, 'interest_rejected', 'مرفوض 1');
    makeNotification($user, 'interest_new', 'حرج', false, '2026-08-03 10:00:00');
    makeNotification($user, 'interest_accepted', 'مقروء', true, '2026-08-04 10:00:00');

    Sanctum::actingAs($user);

    $this->putJson('/api/notifications/read-all')
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.marked', 3)  // 3 غير مقروءة فقط.
        ->assertJsonPath('unread_count', 0);
});

it('requires authentication for notification endpoints (401 · دستور V)', function () {
    $this->getJson('/api/notifications')->assertStatus(401);
    $this->getJson('/api/notifications/recent')->assertStatus(401);
    $this->getJson('/api/notifications/unread-count')->assertStatus(401);
    $this->putJson('/api/notifications/read-all')->assertStatus(401);
});
