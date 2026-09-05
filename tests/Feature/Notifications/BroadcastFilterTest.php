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
 * مرشّح البث — SC-002 · EPIC-09 (US-048 · T076).
 *
 * الإدراج في DB لا يفشل أبداً حتى مع تعطيل البث (null driver)،
 * والبث لا يُطلق إلا مرة واحدة للحرج وصفر لغير الحرجة (الحارس الصارم).
 */
beforeEach(function () {
    config(['scout.driver' => 'null']);
    $this->service = app(NotificationService::class);
    $this->user = User::factory()->ideaOwner()->create();
});

it('inserts a critical notification even when the broadcast driver is disabled (US-049 · T076)', function () {
    config(['broadcasting.default' => 'null']);

    $notification = $this->service->notify(
        $this->user->id,
        NotificationType::EVALUATION_COMPLETED->value,
        'اكتمل التقييم',
    );

    $this->assertDatabaseHas('notifications', [
        'id' => $notification->id,
        'type' => 'evaluation_completed',
        'is_critical' => true,
    ]);
});

it('broadcasts exactly once for a critical type and zero for non-critical types (US-048 · T076)', function () {
    Event::fake();

    // حرج → بث واحد.
    $this->service->notify(
        $this->user->id,
        NotificationType::INTEREST_NEW->value,
        'طلب اهتمام جديد',
        null,
        ['url' => '/projects/1', 'project_id' => 1],
    );

    Event::assertDispatchedTimes(CriticalNotificationBroadcast::class, 1);

    // ثلاثة غير حرجة → صفر بث إضافي.
    $this->service->notify($this->user->id, NotificationType::INTEREST_ACCEPTED->value, 'مقبول');
    $this->service->notify($this->user->id, NotificationType::INTEREST_REJECTED->value, 'مرفوض');
    $this->service->notify($this->user->id, NotificationType::ANALYSIS_COMPLETED->value, 'تحليل');

    Event::assertDispatchedTimes(CriticalNotificationBroadcast::class, 1);

    // كل الأربعة مخزَّنة — لا فقدان (الجدول مصدر الحقيقة).
    $this->assertDatabaseCount('notifications', 4);
});

it('broadcasts to the private notifications channel of the recipient only (T072 · دستور V)', function () {
    Event::fake();

    $this->service->notify(
        $this->user->id,
        NotificationType::INTEREST_NEW->value,
        'طلب اهتمام',
    );

    Event::assertDispatched(CriticalNotificationBroadcast::class, function (CriticalNotificationBroadcast $event) {
        $channels = $event->broadcastOn();

        // PrivateChannel يضيف بادئة `private-` تلقائياً (قناة سلكية private-notifications.{id}).
        return count($channels) === 1
            && $channels[0]->name === 'private-notifications.'.$this->user->id;
    });

    // لا بث لمستخدم آخر (لا تسريب عبر قناة عامة).
    Event::assertDispatched(CriticalNotificationBroadcast::class, fn (CriticalNotificationBroadcast $event) => $event->notification->user_id === $this->user->id);
});

it('exposes the notification payload via the resource for the Echo client (T074 · US-048)', function () {
    Event::fake();

    $this->service->notify(
        $this->user->id,
        NotificationType::INTEREST_NEW->value,
        'طلب اهتمام جديد',
        'مستثمر مهتم بمشروعك',
        ['url' => '/projects/9', 'project_id' => 9],
    );

    Event::assertDispatched(CriticalNotificationBroadcast::class, function (CriticalNotificationBroadcast $event) {
        $payload = $event->broadcastWith()['notification'];

        return $payload['type'] === 'interest_new'
            && $payload['title'] === 'طلب اهتمام جديد'
            && $payload['is_critical'] === true
            && $payload['url'] === '/projects/9';
    });
});
