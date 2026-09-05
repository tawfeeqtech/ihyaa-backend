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
 * حدث تقييم جزئي (3-4/5 أبعاد) — plan.md §1.6/§7 (SRS-AI-F03 · US-016-S6).
 *
 * استخدام مزدوج: حدث تطبيقي يحمل Evaluation · وحمولة بث تحمل Notification
 * (القناة private-users.{owner_id} — event name: evaluation.partial).
 */
class EvaluationPartial implements ShouldBroadcast
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
        return 'evaluation.partial';
    }

    public function broadcastWith(): array
    {
        $notification = $this->evaluation;

        return [
            'notification_id' => $notification->id,
            'project_id' => $notification->data['project_id'] ?? null,
            'project_title' => $notification->data['project_title'] ?? null,
            'evaluation_id' => $notification->data['evaluation_id'] ?? null,
            'missing_dimensions' => $notification->data['missing_dimensions'] ?? [],
            'message' => $notification->title,
            'url' => $notification->data['url'] ?? null,
        ];
    }
}
