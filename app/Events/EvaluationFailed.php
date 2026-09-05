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
 * حدث فشل تقييم AI — plan.md §2.3/§7 (US-019 · SRS-AI-F04).
 *
 * استخدام مزدوج: حدث تطبيقي يحمل Evaluation (من EvaluationService) ·
 * وحمولة بث تحمل Notification (من مستمع الإشعارات — القناة private-users.{owner_id}).
 */
class EvaluationFailed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Evaluation|Notification $evaluation) {}

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
        return 'evaluation.failed';
    }

    public function broadcastWith(): array
    {
        $notification = $this->evaluation;

        return [
            'notification_id' => $notification->id,
            'project_id' => $notification->data['project_id'] ?? null,
            'evaluation_id' => $notification->data['evaluation_id'] ?? null,
            'can_retry' => $notification->data['can_retry'] ?? true,
            'message' => $notification->title,
            'url' => $notification->data['url'] ?? null,
        ];
    }
}
