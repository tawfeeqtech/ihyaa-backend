<?php

namespace App\Jobs;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * مزامنة مشروع واحد مع فهرس البحث — قناة `search-sync` · 5 محاولات تصاعدية (plan §5.3 · T128 · US-034-S5).
 *
 * يُطلق من ProjectObserver عند فشل المزامنة التلقائية، ويُسجَّل في `search_sync_logs`
 * لمراقبة المشرف — بلا فرق صامت. يسترجع المشروع بـ withTrashed ليدعم الحذف الناعم.
 */
class SyncProjectToSearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    /** تأخيرات تصاعدية: 30s → 2m → 5m → 10m */
    public array $backoff = [30, 120, 300, 600];

    public function __construct(
        public int $projectId,
    ) {
        $this->onQueue('search-sync');
    }

    public function handle(): void
    {
        $project = Project::withTrashed()->find($this->projectId);

        if (! $project) {
            return;
        }

        $action = 'searchable';

        try {
            if (! $project->trashed() && $project->publication_status?->value === 'published') {
                $project->searchable();
            } else {
                $action = 'unsearchable';
                $project->unsearchable();
            }

            $this->recordLog($project, $action, 'success');
        } catch (Throwable $e) {
            $this->recordLog($project, $action, 'failed', $e->getMessage());
            Log::warning("[search-sync] فشلت مزامنة المشروع #{$this->projectId}: {$e->getMessage()}");

            throw $e; // Laravel يعيد المحاولة تلقائياً عبر tries + backoff
        }
    }

    /**
     * الفشل النهائي بعد استنفاد المحاولات — تنبيه المشرف عبر السجل.
     */
    public function failed(Throwable $e): void
    {
        Log::error("[search-sync] فشل نهائي لمزامنة المشروع #{$this->projectId}: {$e->getMessage()}");
    }

    /**
     * تسجيل search_sync_logs (US-034-S5) — مع حارس: الجدول/النموذج يُنشآن في مهام لاحقة
     * (T017/T020) فلا يكسر الجدول في حال غيابه.
     */
    private function recordLog(Project $project, string $action, string $status, ?string $error = null): void
    {
        if (! class_exists(\App\Models\SearchSyncLog::class) || ! Schema::hasTable('search_sync_logs')) {
            return;
        }

        try {
            \App\Models\SearchSyncLog::create([
                'indexable_type' => Project::class,
                'indexable_id' => $project->getKey(),
                'action' => $action,
                'status' => $status,
                'error' => $error,
                'attempts' => $this->attempts(),
                'last_attempt_at' => now(),
                'resolved_at' => $status === 'success' ? now() : null,
            ]);
        } catch (Throwable $logError) {
            Log::warning("[search-sync] تعذّر تسجيل سجل المزامنة: {$logError->getMessage()}");
        }
    }
}
