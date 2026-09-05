<?php

namespace App\Events;

use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * البث الفوري للإشعارات الحرجة — T072 · EPIC-09 (US-048).
 *
 * - ينفّذ ShouldBroadcastNow (بث متزامن، لا صف Queue) لضمان وصول ≤ 5 ثوانٍ.
 * - قناة خاصة: `notifications.{user_id}` → private-notifications.{user_id}
 *   (مصادقة المالك في routes/channels.php · T003).
 * - اسم الحدث: `notification.received` — يستمع إليه عميل Echo في الواجهة
 *   لتحديث الجرس/Badge فوراً (T074).
 * - الحمولة: NotificationResource (id, type, title, body, data, url, ...).
 *
 * الشرط المسبق (US-048 السيناريو 6): يُبث فقط عندما يكون النوع حرجياً في
 * config/notifications.php (interest_new · evaluation_completed) — يضمنه
 * NotificationService قبل استدعاء البث.
 *
 * Scramble: WebSocket event — القناة `private-notifications.{user_id}` ·
 * الحدث `notification.received` · الحمولة NotificationResource.
 */
class CriticalNotificationBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Notification $notification) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('notifications.'.$this->notification->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.received';
    }

    public function broadcastWith(): array
    {
        return [
            'notification' => (new NotificationResource($this->notification))->resolve(),
        ];
    }
}
