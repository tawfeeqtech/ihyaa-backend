<?php

namespace App\Services\Project;

use App\Models\Project;
use App\Models\User;
use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * T160 · T072 — سلة المهملات (SRS-API-35..37 · SRS-F02-06 · trash-api.md).
 *
 * الاسترجاع خلال 30 يوماً · الحذف النهائي يمسح ملفات القرص ثم المشروع.
 *
 * (T072) حراسة TOCTOU: الاسترجاع والحذف النهائي يمرّان عبر معاملة مع lockForUpdate
 * وإعادة فحص deleted_at — يحمي من التزامن مع أمر التنظيف المجدول (data-model.md §6.5).
 *
 * (قرارات خطأ HTTP — PROJECT_NOT_TRASHED / TRASH_EXPIRED — تُرمى كـ DomainException
 *  ويلتقطها TrashController ويحوّلها إلى استجابات ApiResponse.)
 */
class TrashService
{
    /** صفحات سلة المهملات — مشاريع المالك المحذوفة خلال 30 يوماً. */
    public function paginate(User $user): LengthAwarePaginator
    {
        return $user->projects()
            ->trash()   // onlyTrashed + ضمن 30 يوماً
            ->with(['category'])
            ->orderByDesc('deleted_at')
            ->paginate(Project::DEFAULT_PAGE_SIZE);
    }

    /** بطاقة سلة المهملات — deleted_at + restore_deadline + purge_at + days_remaining + restorable (trash-api.md §1). */
    public function card(Project $project): array
    {
        $deadline = $project->deleted_at?->copy()->addDays(Project::TRASH_RECOVERY_DAYS);

        // الأيام المتبقية — حساب عددي صريح (يتجنب اختلاف توقيعات Carbon).
        // قد يكون سالباً إذا تأخر أمر التنظيف (contract: يعرض "الحذف النهائي وشيك").
        $daysRemaining = $project->deleted_at
            ? (int) ceil(($deadline->timestamp - now()->timestamp) / 86400)
            : null;

        return array_merge($project->toCardArray(), [
            'deleted_at' => $project->deleted_at?->toISOString(),
            // موعد انتهاء مهلة الاسترجاع — contract (T166 · التوافق الخلفي)
            'restore_deadline' => $deadline?->toISOString(),
            // الحقل المعتمد للعد التنازلي — trash-api.md §1
            'purge_at' => $deadline?->toISOString(),
            'days_remaining' => $daysRemaining,
            // false → الواجهة تخفي زر الاسترجاع (الخادم يبقى الحارس النهائي · 410)
            'restorable' => $daysRemaining !== null && $daysRemaining > 0,
        ]);
    }

    /**
     * استرجاع مشروع من السلة (SRS-API-36 · trash-api.md §2).
     *
     * حراسة TOCTOU: lockForUpdate + إعادة فحص deleted_at/المهلة داخل معاملة —
     * يحمي من التزامن مع PurgeTrashedProjects (data-model.md §6.5).
     * الاسترجاع يعيد تسليح حماية طلبات الاهتمام تلقائياً (InterestService::send
     * يرفض المهملات فقط — بعد restore يعود المشروع متاحاً) ويعيد مزامنة Scout.
     *
     * @throws DomainException برمز PROJECT_NOT_TRASHED أو TRASH_EXPIRED
     */
    public function restore(Project $project): void
    {
        if (! $project->trashed()) {
            throw new DomainException('PROJECT_NOT_TRASHED');
        }

        DB::transaction(function () use ($project) {
            // قفل الصف — أي حذف نهائي متزامن ينتظر حتى انتهاء المعاملة.
            $locked = Project::withTrashed()->lockForUpdate()->findOrFail($project->id);

            // إعادة فحص الحالة بعد القفل (TOCTOU guard).
            if (! $locked->trashed()) {
                throw new DomainException('PROJECT_NOT_TRASHED');
            }

            // انتهت مدة الاسترجاع (30 يوماً) → حذف نهائي فقط.
            if ($locked->deleted_at->lt(now()->subDays(Project::TRASH_RECOVERY_DAYS))) {
                throw new DomainException('TRASH_EXPIRED');
            }

            $locked->restore();

            // مزامنة فهرس البحث صراحةً (ProjectObserver::restored يفعلها أيضاً —
            // نداء صريح يضمنها حتى لو غُيّر المرصاد لاحقاً · trash-api.md §2).
            if (method_exists($locked, 'searchable')) {
                $locked->searchable();
            }
        });
    }

    /**
     * حذف نهائي (SRS-API-37 · trash-api.md §3) — يمسح ملفات Local Disk ثم
     * المشروع نهائياً + سجل تدقيق (Log). حراسة TOCTOU كالاسترجاع.
     * (ProjectFile لا يستخدم SoftDeletes — withTrashed غير صالح هنا.)
     *
     * @throws DomainException برمز PROJECT_NOT_TRASHED
     */
    public function forceDelete(Project $project): void
    {
        if (! $project->trashed()) {
            throw new DomainException('PROJECT_NOT_TRASHED');
        }

        DB::transaction(function () use ($project) {
            $locked = Project::withTrashed()->lockForUpdate()->findOrFail($project->id);

            if (! $locked->trashed()) {
                throw new DomainException('PROJECT_NOT_TRASHED');
            }

            foreach ($locked->files()->get() as $file) {
                Storage::disk('public')->delete($file->file_path);
            }

            $locked->forceDelete();

            // سجل تدقيق — trash-api.md §4 (لا بقايا في البحث: forceDelete يصدر أمر حذف لـ Scout).
            Log::info('trash.force_deleted', [
                'project_id' => $locked->id,
                'title' => $locked->title,
                'user_id' => $locked->user_id,
                'by_user_id' => auth()->id(),
            ]);
        });
    }
}
