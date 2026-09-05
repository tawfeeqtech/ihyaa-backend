<?php

namespace App\Http\Controllers\Api;

use App\Models\Project;
use App\Services\Project\TrashService;
use App\Support\Traits\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * سلة المهملات — SRS-API-35..37 · SRS-F02-06.
 * الاسترجاع خلال 30 يوماً · الحذف النهائي التلقائي بعدها (أمر مجدول projects:purge-trash).
 *
 * T160: المنطق في TrashService · T162: التفويض عبر ProjectPolicy (بدل isOwner()).
 */
class TrashController
{
    use ApiResponse;

    public function __construct(private readonly TrashService $trash)
    {
    }

    /** RL-IO-10 · 20/دقيقة */
    public function index(Request $request): JsonResponse
    {
        $projects = $this->trash->paginate($request->user());

        $data = $projects->map(fn (Project $p) => $this->trash->card($p));

        return $this->paginated($projects, $data);
    }

    /** RL-IO-11 · 10/دقيقة */
    public function restore(Request $request, Project $project): JsonResponse
    {
        if ($request->user()->cannot('restore', $project)) {
            return $this->forbidden();
        }

        try {
            $this->trash->restore($project);
        } catch (DomainException $e) {
            if ($e->getMessage() === 'PROJECT_NOT_TRASHED') {
                return $this->unprocessable('PROJECT_NOT_TRASHED', __('trash.not_trashed'));
            }

            if ($e->getMessage() === 'TRASH_EXPIRED') {
                return $this->error('TRASH_EXPIRED', __('trash.expired'), 410);
            }

            throw $e;
        }

        return $this->success(['restored' => true], __('trash.restored'));
    }

    /** RL-IO-12 · 10/دقيقة */
    public function forceDelete(Request $request, Project $project): JsonResponse
    {
        if ($request->user()->cannot('forceDelete', $project)) {
            return $this->forbidden();
        }

        // T136 — الحذف النهائي إجراء مدمر: يتطلب تأكيداً صريحاً (confirm: true)
        if (! $request->boolean('confirm')) {
            return $this->unprocessable(
                'CONFIRMATION_REQUIRED',
                __('trash.confirm_required'),
                ['confirm' => ['مطلوب true']],
            );
        }

        try {
            $this->trash->forceDelete($project);
        } catch (DomainException $e) {
            if ($e->getMessage() === 'PROJECT_NOT_TRASHED') {
                return $this->unprocessable('PROJECT_NOT_TRASHED', __('trash.not_trashed'));
            }

            throw $e;
        }

        return $this->noContent(__('trash.force_deleted'));
    }
}
