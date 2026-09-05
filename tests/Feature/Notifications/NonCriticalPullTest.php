<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationType;
use App\Events\CriticalNotificationBroadcast;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * الأحداث غير الحرجة تُجلب عند إعادة التحميل — US-049 · T080.
 *
 * لا WebSocket للأحداث غير الحرجة (interest_accepted, interest_rejected, ...):
 * تُخزَّن في DB فوراً، وعند إعادة تحميل الصفحة يقرأ الجرس/الصفحة من الجدول
 * (مصدر الحقيقة الوحيد) — بلا بث وبدون فقدان.
 */
beforeEach(function () {
    config(['scout.driver' => 'null']);
    $this->service = app(NotificationService::class);
    $this->user = User::factory()->ideaOwner()->create();
});

it('stores non-critical events silently and serves them on the next reload (US-049 · T080)', function () {
    Event::fake();

    // أحداث غير حرجة (تحديثات حالة) — بلا بث.
    $accepted = $this->service->notify($this->user->id, NotificationType::INTEREST_ACCEPTED->value, 'تم قبول طلبك', null, ['url' => '/projects/2', 'project_id' => 2]);
    $rejected = $this->service->notify($this->user->id, NotificationType::INTEREST_REJECTED->value, 'تم رفض طلبك', null, ['url' => '/projects/2', 'project_id' => 2]);
    $analysis = $this->service->notify($this->user->id, NotificationType::ANALYSIS_COMPLETED->value, 'اكتمل التحليل', null, ['url' => '/projects/3', 'project_id' => 3]);

    // تواريخ متميزة للترتيب القطعي (created_at DESC) — الأحدث (التحليل) أولاً.
    $analysis->forceFill(['created_at' => '2026-08-03 10:00:00', 'updated_at' => '2026-08-03 10:00:00'])->save();
    $rejected->forceFill(['created_at' => '2026-08-02 10:00:00', 'updated_at' => '2026-08-02 10:00:00'])->save();
    $accepted->forceFill(['created_at' => '2026-08-01 10:00:00', 'updated_at' => '2026-08-01 10:00:00'])->save();

    Event::assertNotDispatched(CriticalNotificationBroadcast::class);
    $this->assertDatabaseCount('notifications', 3);

    // إعادة تحميل الصفحة → الجلب من الجدول يقرأها كلها (الأحدث أولاً).
    Sanctum::actingAs($this->user);

    $this->getJson('/api/notifications')
        ->assertStatus(200)
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.unread_count', 3);

    // الجرس المنسدل (آخر 5) يراها أيضاً.
    $this->getJson('/api/notifications/recent')
        ->assertStatus(200)
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.type', 'analysis_completed')
        ->assertJsonPath('data.2.type', 'interest_accepted');
});

it('marks every pulled non-critical notification as read on reload (read-all → unread_count 0 · US-047)', function () {
    $this->service->notify($this->user->id, NotificationType::INTEREST_CANCELLED->value, 'أُلغي الطلب');

    Sanctum::actingAs($this->user);

    $this->putJson('/api/notifications/read-all')
        ->assertStatus(200)
        ->assertJsonPath('data.marked', 1)
        ->assertJsonPath('unread_count', 0);
});
