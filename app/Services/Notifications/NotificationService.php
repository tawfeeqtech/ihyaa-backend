<?php

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Events\CriticalNotificationBroadcast;
use App\Models\Notification;

/**
 * نقطة الإنشاء الوحيدة للإشعارات — T026 · EPIC-09 (US-047/048/049).
 *
 * القاعدة (SC-002 · US-048):
 *  - إدراج في DB دائماً — الجدول هو مصدر الحقيقة الوحيد (لا فقدان أبداً).
 *  - البث عبر Reverb فقط للأنواع الحرجية (interest_new · evaluation_completed)
 *    وبشرط أن ينتمي النوع لكتالوج config/notifications.php — حارس صارم ضد
 *    تضخم الاتصال (حتى لو مرَّر المتصل is_critical=true لنوع غير حرج لا يُبث).
 *
 * كل منشئي الإشعارات (InterestService · NotifyEvaluationCompleted ·
 * SendEvaluationNotification · AuthController · Notification::pushNotification)
 * يمرّون عبر هذه النقطة — بثٌّ واحد فقط لكل إشعار حرج.
 */
class NotificationService
{
    /**
     * إنشاء إشعار + بث فوري للحرج.
     *
     * @param  int  $userId  المستلم
     * @param  string  $type  نوع الإشعار (مفتاح في كتالوج config/notifications.php)
     * @param  string  $title  نص عربي/إنجليزي جاهز للعرض
     * @param  string|null  $body  نص تفصيلي اختياري
     * @param  array  $data  بيانات سياقية {project_id, interest_id, url, ...}
     * @param  bool|null  $isCritical  تجاوز الحرجية؛ عند null تُقرأ من الكتالوج.
     *                                 ملاحظة: حتى true لا يُبث لنوع غير حرج (الحارس الصارم).
     */
    public function notify(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        array $data = [],
        ?bool $isCritical = null,
    ): Notification {
        // حرجية الكتالوج (مصدر الحقيقة) — تغطي الأنواع السبعة + أي نوع تاريخي خارجها.
        $catalogCritical = (bool) config("notifications.types.{$type}.is_critical", false);

        // ما سيُخزَّن في DB: تجاوز المتصل أو الحرجية الكتالوجية.
        $storedCritical = $isCritical ?? $catalogCritical;

        // الحارس الصارم: يُبث فقط إذا كان النوع حرجياً في الكتالوج بالفعل
        // (قيد US-048 ضد تضخم الاتصال — لا بث لغير الحرجة حتى بطلب صريح).
        $shouldBroadcast = $storedCritical && $catalogCritical;

        $notification = Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'is_critical' => $storedCritical,
        ]);

        if ($shouldBroadcast) {
            broadcast(new CriticalNotificationBroadcast($notification));
        }

        return $notification;
    }

    /**
     * وسم النوع كحرج في الكتالوج — مفيد للاختبارات والتحقق.
     */
    public function isCriticalType(string $type): bool
    {
        return in_array($type, NotificationType::catalog(), true)
            && in_array($type, config('notifications.critical_types', []), true);
    }
}
