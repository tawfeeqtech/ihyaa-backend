<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * الحذف النهائي لمشاريع سلة المهملات بعد 30 يوماً (SRS-F02-06 · trash-api.md §4).
 * الأمر المجدول اليومي: projects:purge-trash
 *
 * T073 — الحراسة:
 *  - `--dry-run`: يعرض المطلوب حذفه دون حذف (آمن للتشغيل اليدوي).
 *  - TOCTOU guard: كل حذف داخل معاملة مع lockForUpdate + إعادة فحص deleted_at
 *    والمهلة — الاسترجاع المتزامن يمنع الحذف (data-model.md §6.5).
 *  - سجل تدقيق Log لكل حذف (trash-api.md §4).
 */
class PurgeTrashedProjects extends Command
{
    protected $signature = 'projects:purge-trash {--dry-run : اعرض المطلوب حذفه دون تنفيذ الحذف}';

    protected $description = 'حذف نهائي للمشاريع التي تجاوزت 30 يوماً في سلة المهملات (مع ملفاتها)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $expired = Project::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays(Project::TRASH_RECOVERY_DAYS))
            ->get();

        $deleted = 0;

        foreach ($expired as $project) {
            if ($dryRun) {
                $this->line("Would purge #{$project->id} «{$project->title}» (deleted_at {$project->deleted_at})");
                $deleted++;
                continue;
            }

            DB::transaction(function () use ($project, &$deleted) {
                // قفل الصف ضمن نطاق المهملات — الاسترجاع المتزامن يُخرج الصف من النطاق (لا يُحذف).
                $locked = Project::onlyTrashed()->lockForUpdate()->find($project->id);

                // حُذف نهائياً أو استُرجِع بين الجلب والقفل → تخطَّه (TOCTOU guard).
                if ($locked === null || ! $locked->trashed()) {
                    return;
                }

                // إعادة فحص المهلة — لا نمس مشروعاً عاد داخل المهلة.
                if ($locked->deleted_at->gte(now()->subDays(Project::TRASH_RECOVERY_DAYS))) {
                    return;
                }

                // حذف الملفات من Local Disk (ProjectFile لا يستخدم SoftDeletes — لا withTrashed).
                $locked->files()->each(function (ProjectFile $file) {
                    Storage::disk('public')->delete($file->file_path);
                });

                $locked->forceDelete();
                $deleted++;

                Log::info('trash.purged', [
                    'project_id' => $locked->id,
                    'title' => $locked->title,
                    'user_id' => $locked->user_id,
                ]);
            });
        }

        $this->info($dryRun
            ? "[dry-run] {$deleted} trashed project(s) would be purged."
            : "Purged {$deleted} trashed project(s).");

        return self::SUCCESS;
    }
}
