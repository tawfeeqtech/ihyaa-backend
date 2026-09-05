<?php

namespace App\Listeners;

use App\Events\EvaluationCompleted;
use App\Events\EvaluationPartial;
use App\Jobs\SyncProjectToSearchJob;
use App\Models\Evaluation;
use App\Models\Project;
use Throwable;

/**
 * مزامنة فهرس Meilisearch عند تغيّر درجة التقييم — plan.md §5.3 (US-034 · FR-245).
 *
 * اكتمال/جزئية ← `$project->searchable()` مباشرة (تحديث وثيقة الدرجة) دون إعادة إرسال
 * أحداث (منع الحلقات). فشل التزامن المباشر ← Job تصاعدي `search-sync` (30s/2m/5m/10m).
 *
 * الحارس `instanceof Evaluation` يمنع إعادة المعالجة عند مرور `broadcast()` بحمولة Notification.
 */
class SyncProjectToSearch
{
    public function handleEvaluationCompleted(EvaluationCompleted $event): void
    {
        if (! $event->evaluation instanceof Evaluation) {
            return;
        }

        $this->sync($event->evaluation->project);
    }

    public function handleEvaluationPartial(EvaluationPartial $event): void
    {
        if (! $event->evaluation instanceof Evaluation) {
            return;
        }

        $this->sync($event->evaluation->project);
    }

    private function sync(?Project $project): void
    {
        if (! $project || $project->trashed() || $project->publication_status?->value !== 'published') {
            return;
        }

        try {
            $project->searchable();
        } catch (Throwable $e) {
            // Meilisearch معطّل/مفقود ← Job إعادة محاولة تصاعدي (T128 · US-034-S5) — لا فرق صامت.
            SyncProjectToSearchJob::dispatch($project->id);
        }
    }
}
