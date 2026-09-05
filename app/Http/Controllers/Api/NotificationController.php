<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\Notifications\NotificationQueries;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * الإشعارات — SRS-API-28..31 · RL-SH-05..08 · EPIC-09 (US-047).
 * جرس الإشعارات: آخر 5 + عدادات — is_critical يُبث عبر Reverb فوراً (US-048).
 *
 * الأسئلة عبر NotificationQueries — الجدول مصدر الحقيقة الوحيد (US-049).
 */
class NotificationController
{
    use ApiResponse;

    public function __construct(
        private readonly NotificationQueries $queries,
    ) {
    }

    /** RL-SH-05 · 60/دقيقة — صفحة كاملة 20/صفحة + unread_count في الهامش. */
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $notifications = $this->queries->paginated($userId, (int) $request->integer('per_page', NotificationQueries::PAGE_SIZE));

        return $this->paginated(
            $notifications,
            NotificationResource::collection($notifications),
            'ok',
            ['unread_count' => $this->queries->unreadCount($userId)],
        );
    }

    /** RL-SH-05 · 60/دقيقة — آخر 5 إشعارات للجرس (القائمة المنسدلة). */
    public function recent(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $recent = $this->queries->recent($userId, (int) $request->integer('limit', NotificationQueries::RECENT_LIMIT));

        return $this->success(
            NotificationResource::collection($recent),
            'ok',
            200,
            ['unread_count' => $this->queries->unreadCount($userId)],
        );
    }

    /** RL-SH-06 · 60/دقيقة — تعيين مقروء (idempotent) + 403 لغير المالك. */
    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        $updated = $this->queries->markRead((int) $request->user()->id, $notification);

        if ($updated === null) {
            return $this->forbidden();
        }

        return $this->success([
            'id' => $updated->id,
            'read_at' => $updated->read_at?->toISOString(),
        ]);
    }

    /** RL-SH-07 · 10/دقيقة — قراءة الكل → marked + unread_count=0. */
    public function markAllRead(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $updated = $this->queries->markAllRead($userId);

        return $this->success(
            ['marked' => $updated],
            'ok',
            200,
            ['unread_count' => $this->queries->unreadCount($userId)],
        );
    }

    /** RL-SH-08 · 120/دقيقة — عدّاد غير المقروء (جرس). */
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->success([
            'unread_count' => $this->queries->unreadCount((int) $request->user()->id),
        ]);
    }
}
