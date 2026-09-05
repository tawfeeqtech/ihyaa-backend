<?php

namespace App\Events;

use App\Models\Evaluation;
use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * حدث اكتمال تقييم AI — قناة: private-users.{owner_id} · plan.md §2.3/§7 (FR-205).
 *
 * استخدام مزدوج:
 *   1) حدث تطبيقي يحمل `Evaluation` — يُطلق من EvaluationService لالتقاط المستمعين
 *      (SendEvaluationNotification · SyncProjectToSearch · InvalidateEvaluationCache).
 *      `broadcastWhen()` يُرجع false فلا يُبث عبر `event()`.
 *   2) حمولة بث تحمل `Notification` — عبر `Notification::broadcastCritical()`
 *      (type = evaluation_completed) و`broadcast()` المباشر — القناة الخاصة للمالك.
 */
class EvaluationCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Evaluation|Notification $evaluation) {}

    /**
     * لا تُبث تلقائياً عند حمل Evaluation (مسار المستمعين) — تُبث فقط عند حمل Notification.
     */
    public function broadcastWhen(): bool
    {
        return $this->evaluation instanceof Notification;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('private-users.'.$this->evaluation->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'evaluation.completed';
    }

    public function broadcastWith(): array
    {
        $notification = $this->evaluation;

        return [
            'notification_id' => $notification->id,
            'project_id' => $notification->data['project_id'] ?? null,
            'project_title' => $notification->data['project_title'] ?? null,
            'evaluation_id' => $notification->data['evaluation_id'] ?? null,
            'ai_score' => $notification->data['ai_score'] ?? null,
            'message' => $notification->title,
            'url' => $notification->data['url'] ?? null,
        ];
    }
}
