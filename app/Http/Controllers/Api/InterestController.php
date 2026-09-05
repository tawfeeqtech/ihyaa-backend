<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\RejectInterestRequest;
use App\Http\Requests\StoreInterestRequest;
use App\Http\Resources\InterestResource;
use App\Models\Interest;
use App\Models\Project;
use App\Services\InterestService;
use App\Support\Traits\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * طلبات الاهتمام — SRS-API-22..27 · SRS-F08 · EPIC-08.
 *
 * Controller رفيع — كل منطق الأعمال في InterestService:
 *  store   (Investor)   → InterestService::send    (تحقق تسلسلي + معاملة + إشعار حرج)
 *  received(صاحب الفكرة) → InterestService::received (ترتيب DESC + فلترة + عدّادات)
 *  sent    (المستثمر)    → InterestService::sent
 *  accept/reject (صاحب المشروع فقط — Policy) → InterestService::accept/reject
 *  cancel  (المستثمر — UC-07 E2) → InterestService::cancel
 */
class InterestController
{
    use ApiResponse, AuthorizesRequests;

    public function __construct(
        private readonly InterestService $service,
    ) {
    }

    // ——————————————————————— إرسال طلب (RL-INV-04 · 10/دقيقة) ———————————————————————

    public function store(StoreInterestRequest $request, Project $project): JsonResponse
    {
        $interest = $this->service->send($request->user(), $project, $request->validated());

        return $this->created(InterestResource::make($interest), __('interests.sent'));
    }

    // ——————————————————————— طلبات مستلمة (صاحب الفكرة — RL-SH-01 · 30/دقيقة) ———————————————————————

    public function received(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isIdeaOwner()) {
            return $this->forbidden();
        }

        $request->validate([
            'status' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        [$interests, $counters] = $this->service->received($user, $request->only(['status', 'per_page']));

        return $this->success(
            // items() لا paginator — ليمنع ResourceCollection من لفّ data/links/meta
            // داخل نفسه (contract §2: data مصفوفة + meta/أخرى على المستوى الأعلى).
            InterestResource::collection($interests->items()),
            'ok',
            200,
            [
                'meta' => [
                    'current_page' => $interests->currentPage(),
                    'per_page' => $interests->perPage(),
                    'total' => $interests->total(),
                    'last_page' => $interests->lastPage(),
                ],
                // عدّادات GROUP BY واحدة — تحديث عند التحميل (US-046 السيناريو 3).
                'counters' => $counters,
            ],
        );
    }

    // ——————————————————————— طلبات مرسلة (المستثمر — RL-INV-05 · 60/دقيقة) ———————————————————————

    public function sent(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isInvestor()) {
            return $this->forbidden();
        }

        $request->validate([
            'status' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        [$interests, $counters] = $this->service->sent($user, $request->only(['status', 'per_page']));

        return $this->success(
            // items() لا paginator — ليمنع ResourceCollection من لفّ data/links/meta
            // داخل نفسه (contract §3: data مصفوفة + meta/أخرى على المستوى الأعلى).
            InterestResource::collection($interests->items()),
            'ok',
            200,
            [
                'meta' => [
                    'current_page' => $interests->currentPage(),
                    'per_page' => $interests->perPage(),
                    'total' => $interests->total(),
                    'last_page' => $interests->lastPage(),
                ],
                'counters' => $counters,
            ],
        );
    }

    // ——————————————————————— قبول (صاحب المشروع — RL-SH-02 · 10/دقيقة) ———————————————————————

    public function accept(Request $request, Interest $interest): JsonResponse
    {
        $this->authorize('accept', $interest);

        $accepted = $this->service->accept($interest);

        return $this->success(InterestResource::make($accepted), __('interests.accepted'));
    }

    // ——————————————————————— رفض (صاحب المشروع — RL-SH-03 · 10/دقيقة) ———————————————————————

    public function reject(RejectInterestRequest $request, Interest $interest): JsonResponse
    {
        $this->authorize('reject', $interest);

        $rejected = $this->service->reject($interest, $request->validated()['rejection_reason'] ?? null);

        return $this->success(InterestResource::make($rejected), __('interests.rejected'));
    }

    // ——————————————————————— إلغاء (المستثمر — UC-07 E2 · UC-12) ———————————————————————

    public function cancel(Request $request, Interest $interest): JsonResponse
    {
        $this->authorize('cancel', $interest);

        $cancelled = $this->service->cancel($interest);

        return $this->success(InterestResource::make($cancelled), __('interests.cancelled'));
    }
}
