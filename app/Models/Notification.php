<?php

namespace App\Models;

use App\Events\EvaluationCompleted;
use App\Events\InterestCreated;
use App\Services\Notifications\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * إشعار داخل التطبيق — SRS-F09 · T022 · EPIC-09.
 * is_critical = true → بث فوري عبر Reverb (interest_new | evaluation_completed).
 * أنواع: interest_received · evaluation_completed (حرجة) · interest_accepted · interest_rejected
 *        interest_cancelled · project_updated · evaluation_failed
 *
 * نقطة الإنشاء الوحيدة عبر NotificationService::notify — الـ static
 * `pushNotification` يفوّض إليه (توافق خلفي للمنشئين القدامى). البث يُقرَّر
 * بكتالوج config/notifications.php (حارس صارم ضد تضخم الاتصال — US-048).
 */
class Notification extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'data',
        'is_critical',
        'read_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'is_critical' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /** آخر N إشعارات للجرس (US-047 · T069) — الأحدث أولاً. */
    public function scopeRecent(Builder $query, int $limit = 5): Builder
    {
        return $query->orderByDesc('created_at')->limit(max(1, $limit));
    }

    /**
     * إنشاء إشعار موحّد + بث فوري للحرج — T026 · US-047/048.
     * يفوّض إلى NotificationService::notify (نقطة الإنشاء الوحيدة).
     * الاسم pushNotification — لأن Model::push() محجوز لـ Eloquent (حفظ العلاقات).
     */
    public static function pushNotification(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        array $data = [],
        bool $isCritical = false,
    ): self {
        return app(NotificationService::class)->notify(
            $userId,
            $type,
            $title,
            $body,
            $data,
            $isCritical,
        );
    }

    /**
     * بث الحدث الحرج عبر القناة الخاصة private-users.{user_id}.
     * أُبقي للتوافق الخلفي — مسار EPIC-09 الجديد يستخدم CriticalNotificationBroadcast
     * عبر NotificationService (قناة notifications.{user_id} · حدث notification.received).
     */
    public function broadcastCritical(): void
    {
        $eventClass = match ($this->type) {
            'interest_received', 'interest_new' => InterestCreated::class,
            'evaluation_completed' => EvaluationCompleted::class,
            default => null,
        };

        if ($eventClass !== null) {
            broadcast(new $eventClass($this));
        }
    }
}
