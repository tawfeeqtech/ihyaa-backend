<?php

namespace App\Observers;

use App\Enums\ProjectStatus;
use App\Jobs\SyncProjectToSearchJob;
use App\Models\Project;
use Throwable;

/**
 * مزامنة فهرس البحث تلقائياً — plan §5.3 · US-034 · FR-245/247 (T127).
 * أي إنشاء/تعديل/حذف/استرجاع ينعكس على فهرس Meilisearch ≤ 5 ثوانٍ — بلا فرق صامت.
 */
class ProjectObserver
{
    public function saved(Project $project): void
    {
        $this->sync($project);
    }

    public function deleted(Project $project): void
    {
        // Soft Delete فقط (سلة 30 يوماً) — data-model §8.4 · plan §5.3
        if ($project->trashed()) {
            $this->sync($project);
        }
    }

    public function restored(Project $project): void
    {
        $this->sync($project);
    }

    /**
     * قاعدة الفهرسة (FR-247): منشور وغير محذوف فقط — لا مسودات، لا سلة.
     */
    public function shouldIndex(Project $project): bool
    {
        return ! $project->trashed()
            && $project->publication_status === ProjectStatus::PUBLISHED;
    }

    /**
     * مزامنة فورية؛ عند فشل Meilisearch يُحوَّل إلى SyncProjectToSearchJob
     * (قناة search-sync · 5 محاولات تصاعدية · سجل search_sync_logs).
     */
    private function sync(Project $project): void
    {
        try {
            $this->shouldIndex($project)
                ? $project->searchable()
                : $project->unsearchable();
        } catch (Throwable $e) {
            SyncProjectToSearchJob::dispatch($project->getKey());
        }
    }
}
