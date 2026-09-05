<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * استعلامات الإشعارات — T027 · EPIC-09 (US-047).
 *
 * - unreadCount()  : عدّاد غير المقروء لجرس الإشعارات (RL-SH-08 · 120/دقيقة).
 * - recent()       : آخر N إشعارات للقائمة المنسدلة (limit=5 · T069).
 * - paginated()    : صفحة الإشعارات الكاملة (20/صفحة · T071).
 * - markRead()     : تعيين إشعار مقروء (idempotent) — null لغير المالك (403).
 * - markAllRead()  : قراءة الكل — يعيد عدد الصفوف المحدّثة.
 *
 * الجدول مصدر الحقيقة الوحيد — لا حالة منفصلة في الجلسة/الذاكرة.
 */
class NotificationQueries
{
    public const PAGE_SIZE = 20;

    public const MAX_PAGE_SIZE = 50;

    public const RECENT_LIMIT = 5;

    public function paginated(int $userId, int $perPage = self::PAGE_SIZE): LengthAwarePaginator
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate($this->normalizePerPage($perPage));
    }

    public function recent(int $userId, int $limit = self::RECENT_LIMIT): Collection
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(max(1, min($limit, self::RECENT_LIMIT)))
            ->get();
    }

    public function unreadCount(int $userId): int
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->unread()
            ->count();
    }

    /**
     * تعيين إشعار مقروء — idempotent (قراءة متكررة لا تُغيّر شيئاً).
     * يعيد null إذا لم يكن المالك — يعالجه الـ Controller كـ 403.
     */
    public function markRead(int $userId, Notification $notification): ?Notification
    {
        if ((int) $notification->user_id !== (int) $userId) {
            return null;
        }

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $notification->refresh();
    }

    /** قراءة الكل — يعيد عدد الصفوف التي حُدِّثت. */
    public function markAllRead(int $userId): int
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->unread()
            ->update(['read_at' => now()]);
    }

    private function normalizePerPage(int $perPage): int
    {
        if ($perPage < 1) {
            return self::PAGE_SIZE;
        }

        return min($perPage, self::MAX_PAGE_SIZE);
    }
}
