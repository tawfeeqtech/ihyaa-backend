<?php

namespace App\Jobs;

use App\Models\Evaluation;
use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * إشعار اكتمال التقييم — plan.md §2.1/§7 (FR-205 · US-016-S5).
 *
 * القناة `notifications` · tries=3 · timeout=30s.
 * Reverb عبر Notification::pushNotification(isCritical) ← broadcastCritical →
 * `broadcast(EvaluationCompleted(Notification))` على القناة private-users.{owner_id}.
 * البريد اختياري خلف `AI_NOTIFY_EMAIL_ENABLED` (قرار تعارض SRS-F09-04 موثق في plan.md).
 */
class NotifyEvaluationCompleted implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public Evaluation $evaluation,
    ) {
        // القناة عبر onQueue() — لا إعادة تعريف خاصية $queue (Queueable يملكها بلا نوع).
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $project = $this->evaluation->project;

        if (! $project) {
            return;
        }

        Notification::pushNotification(
            (int) $project->user_id,
            'evaluation_completed',
            'اكتمل تقييم مشروعك',
            null,
            [
                'project_id' => $project->id,
                'project_title' => $project->title,
                'evaluation_id' => $this->evaluation->id,
                'ai_score' => $this->evaluation->overall_score,
                'status' => $this->evaluation->status->value,
                'url' => '/projects/'.$project->id,
            ],
            isCritical: true,
        );

        if ($this->emailEnabled()) {
            $owner = $project->owner;

            if ($owner) {
                Mail::raw(
                    sprintf(
                        'اكتمل تقييم مشروعك «%s» على منصة إحياء — الدرجة: %s.'."\n\n".'تفاصيل المشروع: %s',
                        (string) $project->title,
                        (string) $this->evaluation->overall_score,
                        url('/projects/'.$project->id),
                    ),
                    fn ($message) => $message->to((string) $owner->email)
                        ->subject('اكتمل تقييم مشروعك — إحياء (Ihyaa)'),
                );
            }
        }
    }

    private function emailEnabled(): bool
    {
        return filter_var(env('AI_NOTIFY_EMAIL_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
    }
}
