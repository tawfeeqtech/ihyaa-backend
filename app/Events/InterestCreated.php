<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * حدث حرج: طلب اهتمام جديد — بث فوري لصاحب الفكرة (< 5 ثوانٍ) · docs/api/enums.md §2.9.
 * قناة: private-users.{owner_id}
 */
class InterestCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Notification $notification) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('private-users.'.$this->notification->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'interest.created';
    }

    public function broadcastWith(): array
    {
        return [
            'notification_id' => $this->notification->id,
            'project_id' => $this->notification->data['project_id'] ?? null,
            'interest_id' => $this->notification->data['interest_id'] ?? null,
            'title' => $this->notification->title,
            'body' => $this->notification->body,
            'url' => $this->notification->data['url'] ?? null,
        ];
    }
}
