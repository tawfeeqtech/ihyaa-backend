<?php

namespace App\Listeners;

use App\Events\EvaluationCompleted;
use App\Events\EvaluationFailed;
use App\Events\EvaluationPartial;
use App\Jobs\NotifyEvaluationCompleted;
use App\Models\Evaluation;
use App\Models\Notification;

/**
 * إشعارات اكتمال/فشل/جزئية التقييم — plan.md §2.3/§7 (FR-205 · US-016-S5).
 *
 * - completed : يُفوَّض إلى Job (NotifyEvaluationCompleted) — Reverb + DB + بريد اختياري.
 * - failed/partial : إشعار DB + بث مباشر (إشعار نادر — بلا Job إضافي).
 *
 * الحارس `instanceof Evaluation` ضروري: `broadcast()` يمرّ عبر مفكّك الأحداث أيضاً
 * (PendingBroadcast::__destruct → dispatcher) بحمولة Notification، فنمنع إعادة المعالجة.
 */
class SendEvaluationNotification
{
    public function handleEvaluationCompleted(EvaluationCompleted $event): void
    {
        if (! $event->evaluation instanceof Evaluation) {
            return;
        }

        NotifyEvaluationCompleted::dispatch($event->evaluation);
    }

    public function handleEvaluationFailed(EvaluationFailed $event): void
    {
        if (! $event->evaluation instanceof Evaluation) {
            return;
        }

        $project = $event->evaluation->project;

        if (! $project) {
            return;
        }

        $notification = Notification::pushNotification(
            (int) $project->user_id,
            'evaluation_failed',
            'فشل تقييم مشروعك — يمكنك إعادة المحاولة',
            null,
            [
                'project_id' => $project->id,
                'project_title' => $project->title,
                'evaluation_id' => $event->evaluation->id,
                'can_retry' => true,
                'status' => 'failed',
                'url' => '/projects/'.$project->id,
            ],
            isCritical: true,
        );

        // Notification::broadcastCritical لا يربط evaluation_failed — نبث صراحةً.
        broadcast(new EvaluationFailed($notification));
    }

    public function handleEvaluationPartial(EvaluationPartial $event): void
    {
        if (! $event->evaluation instanceof Evaluation) {
            return;
        }

        $project = $event->evaluation->project;

        if (! $project) {
            return;
        }

        $missing = is_array($event->evaluation->result)
            ? ($event->evaluation->result['partial_dimensions'] ?? [])
            : [];

        $notification = Notification::pushNotification(
            (int) $project->user_id,
            'evaluation_partial',
            'اكتمل تقييم مشروعك جزئياً ('.$this->completedCount($event->evaluation).' من 5 أبعاد)',
            null,
            [
                'project_id' => $project->id,
                'project_title' => $project->title,
                'evaluation_id' => $event->evaluation->id,
                'missing_dimensions' => $missing,
                'status' => 'partial',
                'url' => '/projects/'.$project->id,
            ],
            isCritical: true,
        );

        broadcast(new EvaluationPartial($notification));
    }

    private function completedCount(Evaluation $evaluation): int
    {
        return is_array($evaluation->result) ? count($evaluation->result['dimensions'] ?? []) : 0;
    }
}
