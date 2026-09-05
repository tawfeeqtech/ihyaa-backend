<?php

namespace App\Services\Saved;

use App\Exceptions\Interest\ProjectUnavailableException;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * المشاريع المحفوظة — US-059 · SRS-API-32/33/34 (T093).
 *
 * Idempotency (US-059/4): الحفظ والإزالة قابلان للتكرار بلا خطأ —
 *   firstOrCreate + التقاط DuplicateEntryException من سباق متزامن → نفس الرد 200.
 * الحفظ لا يقبل مشروعاً soft-deleted (يُطرح قبل الوصول هنا — الـ route binding
 * يُرجع 404 لغير الظاهر؛ الحارس حماية للاستخدام المباشر من الخدمة).
 */
class SavedProjectService
{
    /**
     * حفظ مشروع — idempotent.
     *
     * @return array{saved: bool, already_saved: bool, saved_id: int}
     *
     * @throws ProjectUnavailableException  مشروع في المهملات (لا يُحفظ — contract §2)
     */
    public function save(User $investor, Project $project): array
    {
        if ($project->trashed()) {
            throw new ProjectUnavailableException();
        }

        try {
            $saved = $investor->savedProjects()->firstOrCreate(['project_id' => $project->id]);
        } catch (QueryException $e) {
            // سباق متزامن — القيد الفريد (user_id, project_id) يلتقط الثاني → 200 already_saved.
            if ($e->getCode() === '23000') {
                $saved = $investor->savedProjects()
                    ->where('project_id', $project->id)
                    ->firstOrFail();

                return ['saved' => true, 'already_saved' => true, 'saved_id' => $saved->id];
            }

            throw $e;
        }

        return [
            'saved' => true,
            'already_saved' => ! $saved->wasRecentlyCreated,
            'saved_id' => $saved->id,
        ];
    }

    /**
     * إزالة من المحفوظات — idempotent (إزالة غير موجودة → removed: false بلا خطأ).
     *
     * @return array{saved: false, removed: bool}
     */
    public function remove(User $investor, Project $project): array
    {
        $deleted = $investor->savedProjects()
            ->where('project_id', $project->id)
            ->delete();

        return ['saved' => false, 'removed' => $deleted > 0];
    }
}
