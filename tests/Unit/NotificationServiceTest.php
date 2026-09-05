<?php

namespace Tests\Unit;

use App\Enums\NotificationType;
use App\Events\CriticalNotificationBroadcast;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * NotificationService — T026 · EPIC-09 (US-047/048 · T073).
 *
 * نقطة الإنشاء الوحيدة: الإدراج في DB دائماً (مصدر الحقيقة — US-049)،
 * والـ broadcast يُطلق فقط للأنواع الحرجية في كتالوج config/notifications.php
 * (الحارس الصارم — لا بث لغير الحرجة حتى بطلب is_critical صريح).
 *
 * نستبدل موزّع الأحداث بـ Event::fake ونرصد CriticalNotificationBroadcast
 * لأن `broadcast()` → PendingBroadcast::__destruct → Event Dispatcher::dispatch
 * (مسار Laravel 13 — ShouldBroadcastNow يُبث عبر BroadcastEvent بشكل متزامن).
 */
beforeEach(function () {
    $this->service = app(NotificationService::class);
    $this->user = User::factory()->ideaOwner()->create();
});

it('stores a critical notification and broadcasts it via Reverb (US-048 · T073)', function () {
    Event::fake();

    $notification = $this->service->notify(
        $this->user->id,
        NotificationType::INTEREST_NEW->value,
        'طلب اهتمام جديد',
        null,
        ['url' => '/projects/1'],
    );

    expect($notification)->toBeInstanceOf(Notification::class);
    expect($notification->is_critical)->toBeTrue();

    $this->assertDatabaseHas('notifications', [
        'id' => $notification->id,
        'user_id' => $this->user->id,
        'type' => 'interest_new',
        'is_critical' => true,
    ]);

    Event::assertDispatched(CriticalNotificationBroadcast::class);
});

it('stores a non-critical notification WITHOUT broadcasting (US-049 · T073)', function () {
    Event::fake();

    $notification = $this->service->notify(
        $this->user->id,
        NotificationType::INTEREST_ACCEPTED->value,
        'تم قبول طلبك',
    );

    expect($notification->is_critical)->toBeFalse();

    $this->assertDatabaseHas('notifications', [
        'id' => $notification->id,
        'type' => 'interest_accepted',
        'is_critical' => false,
    ]);

    Event::assertNotDispatched(CriticalNotificationBroadcast::class);
});

it('applies the strict guard: is_critical override does NOT broadcast a non-catalog type (US-048 · SC-002 · T073)', function () {
    Event::fake();

    // نوع غير حرج في الكتالوج (analysis_completed) مع تجاوز is_critical=true
    // → يُخزَّن حرجاً (قد تكون له دلالة داخلية) لكن لا يُبث إطلاقاً.
    $notification = $this->service->notify(
        $this->user->id,
        NotificationType::ANALYSIS_COMPLETED->value,
        'اكتمل التحليل',
        null,
        [],
        true,
    );

    expect($notification->is_critical)->toBeTrue();
    $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'is_critical' => true]);

    Event::assertNotDispatched(CriticalNotificationBroadcast::class);
});

it('exposes catalog criticality through the enum reading config (T018 · T073)', function () {
    expect(NotificationType::INTEREST_NEW->isCritical())->toBeTrue();
    expect(NotificationType::EVALUATION_COMPLETED->isCritical())->toBeTrue();

    expect(NotificationType::INTEREST_ACCEPTED->isCritical())->toBeFalse();
    expect(NotificationType::INTEREST_REJECTED->isCritical())->toBeFalse();
    expect(NotificationType::INTEREST_CANCELLED->isCritical())->toBeFalse();
    expect(NotificationType::ANALYSIS_COMPLETED->isCritical())->toBeFalse();
    expect(NotificationType::PDF_GENERATION_FAILED->isCritical())->toBeFalse();

    expect(NotificationType::catalog())->toBe([
        'interest_new',
        'evaluation_completed',
        'interest_accepted',
        'interest_rejected',
        'interest_cancelled',
        'analysis_completed',
        'pdf_generation_failed',
    ]);
});

it('always inserts even when the broadcast driver is disabled/null (US-049 · T076)', function () {
    config(['broadcasting.default' => 'null']);

    // دون Event::fake — مع BROADCAST_CONNECTION=null يجب ألا يفشل الإدراج.
    $notification = $this->service->notify(
        $this->user->id,
        NotificationType::EVALUATION_COMPLETED->value,
        'اكتمل التقييم',
        null,
        ['url' => '/projects/3'],
    );

    $this->assertDatabaseHas('notifications', [
        'id' => $notification->id,
        'type' => 'evaluation_completed',
        'is_critical' => true,
    ]);
});
