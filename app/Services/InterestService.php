<?php

namespace App\Services;

use App\Enums\InterestStatus;
use App\Exceptions\Interest\DuplicateInterestException;
use App\Exceptions\Interest\InvalidInterestStateException;
use App\Exceptions\Interest\ProjectUnavailableException;
use App\Exceptions\Interest\SelfInterestException;
use App\Models\Interest;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Services\Agreement\AgreementService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * T033 · T045 · T055 · T057 — خدمة طلبات الاهتمام (EPIC-08 · US-041..046).
 *
 * النواة المركزية لمسار الاهتمام — كل منطق الأعمال هنا والـ Controller رفيع:
 *
 *  - send()     : التحقق التسلسلي (مشروع متاح ← لا اهتمام ذاتي ← اكتمال ملف
 *                 المستثمر ← منع التكرار) ثم إنشاء داخل معاملة + إشعار حرج
 *                 `interest_new` (US-041 · US-043 · contract §1).
 *  - accept()   : معاملة + lockForUpdate + التحقق pending (409) + آلة الحالات
 *                 + تفويض توليد PDF/إنشاء الاتفاق لـ AgreementService (US-044/045).
 *  - reject()   : معاملة + lockForUpdate + pending (409) + آلة الحالات + سبب.
 *  - cancel()   : آلة الحالات + حذف ملف PDF/سجل الاتفاق بعد القبول + إشعار (UC-07 E2).
 *  - received() : لوحة صاحب الفكرة — ترتيب DESC + فلترة status + ترقيم 12
 *                 + عدّادات GROUP BY واحدة (لا N+1) (US-046).
 *  - sent()     : لوحة المستثمر — نفس بنية received.
 */
class InterestService
{
    /** الترقيم الافتراضي للوحات — contract §2/§3 (معيار US-040). */
    public const PAGE_SIZE = 12;

    /** السقف الأقصى لـ per_page — contract §2/§3 (1..50). */
    public const MAX_PAGE_SIZE = 50;

    public function __construct(
        private readonly InterestDuplicateGuard $duplicateGuard,
        private readonly ProfileCompletenessService $profileCompleteness,
        private readonly InterestStatusMachine $machine,
        private readonly AgreementService $agreementService,
    ) {
    }

    // ——————————————————————— إرسال طلب (US-041 · US-042 · US-043) ———————————————————————

    /**
     * التحقق التسلسلي ثم إنشاء الطلب داخل معاملة واحدة.
     *
     * @param  array{interest_type: string, message?: string|null}  $data
     *
     * @throws ProjectUnavailableException   المشروع محذوف (UC-06 E2)
     * @throws SelfInterestException         المتصل هو مالك المشروع
     * @throws ProfileIncompleteException    ملف المستثمر ناقص (UC-06 A1)
     * @throws DuplicateInterestException    طلب نشط سابق أو سباق فهرس فريد (US-043)
     */
    public function send(User $investor, Project $project, array $data): Interest
    {
        // 1) المشروع موجود وغير محذوف (UC-06 E2).
        if ($project->trashed()) {
            throw new ProjectUnavailableException();
        }

        // 2) لا اهتمام ذاتي — المتصل ليس مالك المشروع (contract §1).
        if ($project->isOwner($investor)) {
            throw new SelfInterestException();
        }

        // 3) اكتمال ملف المستثمر الإلزامي (UC-06 A1 — لا يُنشأ طلب).
        $this->profileCompleteness->assertInvestorComplete($investor);

        // 4) منع الطلب النشط المكرر — طبقة التطبيق.
        $this->duplicateGuard->assertNoActive($project->id, $investor->id);

        // 5) إنشاء داخل معاملة + إشعار حرج (interest_new — يُبث عبر Reverb).
        try {
            return DB::transaction(function () use ($investor, $project, $data) {
                $interest = $project->interests()->create([
                    'investor_id' => $investor->id,
                    'interest_type' => $data['interest_type'],
                    'message' => $data['message'] ?? null,
                    'status' => InterestStatus::PENDING,
                ]);

                Notification::pushNotification(
                    $project->user_id,
                    'interest_new',
                    __('notifications.interest_received_title', ['project' => $project->title]),
                    $investor->name.' — '.$data['interest_type'],
                    [
                        'project_id' => $project->id,
                        'interest_id' => $interest->id,
                        'url' => '/projects/'.$project->id,
                    ],
                    true,
                );

                return $interest;
            });
        } catch (QueryException $e) {
            // طبقة قاعدة البيانات: الفهرس الفريد (project_id, active_dup_key) يلتقط
            // السباق — SQLSTATE 23000 (Duplicate entry) → 422 duplicate_interest (T042).
            if ($e->getCode() === '23000') {
                throw new DuplicateInterestException();
            }

            throw $e;
        }
    }

    // ——————————————————————— قبول (US-044 · US-045) ———————————————————————

    /**
     * قبول الطلب: معاملة + lockForUpdate + التحقق pending (خلاف ذلك 409)
     * + آلة الحالات + تفويض توليد PDF/إنشاء الاتفاق لـ AgreementService.
     *
     * @throws InvalidInterestStateException  الحالة ليست pending (409)
     */
    public function accept(Interest $interest): Interest
    {
        return DB::transaction(function () use ($interest) {
            $locked = $this->lockInterest($interest);

            $this->assertPending($locked);

            // عبر آلة الحالات — الفاعل: مالك المشروع (أو المشرف).
            if (! $this->machine->canTransition($locked->status, InterestStatus::ACCEPTED, InterestStatusMachine::ROLE_OWNER)) {
                throw new InvalidInterestStateException();
            }

            // تفويض توليد PDF + إنشاء سجل الاتفاق (معاملته الداخلية قفل + pending).
            $accepted = $this->agreementService->accept($locked);

            // إشعار غير حرج للمستثمر — فقط عند نجاح PDF (لا في الحالة الوسيطة).
            if ($accepted->status === InterestStatus::ACCEPTED) {
                $accepted->load('agreement');

                Notification::pushNotification(
                    $accepted->investor_id,
                    'interest_accepted',
                    __('notifications.interest_accepted_title', ['project' => $accepted->project->title]),
                    null,
                    ['project_id' => $accepted->project_id, 'interest_id' => $accepted->id, 'url' => '/projects/'.$accepted->project_id],
                    false,
                );
            }

            return $accepted;
        });
    }

    // ——————————————————————— رفض (US-044) ———————————————————————

    /**
     * رفض الطلب مع سبب اختياري ≤ 500 حرف.
     *
     * @throws InvalidInterestStateException  الحالة ليست pending (409)
     */
    public function reject(Interest $interest, ?string $reason = null): Interest
    {
        return DB::transaction(function () use ($interest, $reason) {
            $locked = $this->lockInterest($interest);

            $this->assertPending($locked);

            if (! $this->machine->canTransition($locked->status, InterestStatus::REJECTED, InterestStatusMachine::ROLE_OWNER)) {
                throw new InvalidInterestStateException();
            }

            $locked->reject($reason);

            Notification::pushNotification(
                $locked->investor_id,
                'interest_rejected',
                __('notifications.interest_rejected_title', ['project' => $locked->project->title]),
                $reason,
                ['project_id' => $locked->project_id, 'interest_id' => $locked->id, 'url' => '/projects/'.$locked->project_id],
                false,
            );

            return $locked;
        });
    }

    // ——————————————————————— إلغاء (UC-07 E2 · UC-12) ———————————————————————

    /**
     * إلغاء الطلب — المستثمر المرسل فقط (pending/accepted/accepted_pending_document).
     * بعد القبول: يُحذف ملف PDF وسجل الاتفاق ويُخفى البريد (عكس كامل للآثار).
     *
     * @throws InvalidInterestStateException  الحالة النهائية لا تُلغى (409)
     */
    public function cancel(Interest $interest): Interest
    {
        return DB::transaction(function () use ($interest) {
            $locked = $this->lockInterest($interest);

            if (! $this->machine->canTransition($locked->status, InterestStatus::CANCELLED, InterestStatusMachine::ROLE_INVESTOR)) {
                throw new InvalidInterestStateException();
            }

            // إلغاء بعد القبول → حذف ملف PDF + سجل الاتفاق (UC-07 E2).
            if ($locked->status === InterestStatus::ACCEPTED) {
                if ($locked->agreement_pdf_path) {
                    Storage::disk('public')->delete($locked->agreement_pdf_path);
                }

                $locked->agreement?->delete();

                $locked->forceFill([
                    'agreement_pdf_path' => null,
                    'agreement_id' => null,
                ])->save();
            }

            $locked->cancel();

            Notification::pushNotification(
                $locked->project->user_id,
                'interest_cancelled',
                __('notifications.interest_cancelled_title', ['project' => $locked->project->title]),
                null,
                ['project_id' => $locked->project_id, 'interest_id' => $locked->id, 'url' => '/projects/'.$locked->project_id],
                false,
            );

            return $locked;
        });
    }

    // ——————————————————————— لوحة صاحب الفكرة (US-046) ———————————————————————

    /**
     * الطلبات الواردة — الأحدث أولاً + فلترة status قابلة للدمج + ترقيم 12
     * + عدّادات GROUP BY واحدة (لا N+1).
     *
     * @return array{0: \Illuminate\Contracts\Pagination\LengthAwarePaginator, 1: array<string, int>}
     */
    public function received(User $owner, array $filters = []): array
    {
        $query = $owner->interestsReceived()
            ->with(['project.category', 'project.files', 'investor']);

        $this->applyStatusFilter($query, $filters['status'] ?? null);

        $interests = $query
            ->orderByDesc('interests.created_at')
            ->paginate($this->perPage($filters));

        return [$interests, $this->counters($owner->interestsReceived())];
    }

    // ——————————————————————— لوحة المستثمر (US-046) ———————————————————————

    /**
     * الطلبات المرسلة — نفس بنية received (بطاقة المشروع كاملة + can_cancel).
     *
     * @return array{0: \Illuminate\Contracts\Pagination\LengthAwarePaginator, 1: array<string, int>}
     */
    public function sent(User $investor, array $filters = []): array
    {
        $query = $investor->interestsSent()
            ->with(['project.category', 'project.files', 'investor']);

        $this->applyStatusFilter($query, $filters['status'] ?? null);

        $interests = $query
            ->orderByDesc('interests.created_at')
            ->paginate($this->perPage($filters));

        return [$interests, $this->counters($investor->interestsSent())];
    }

    // ——————————————————————— أدوات خاصة ———————————————————————

    /** قفل الصف لمنع المعالجات المتوازية (US-044 السيناريو 4). */
    private function lockInterest(Interest $interest): Interest
    {
        return Interest::query()->lockForUpdate()->findOrFail($interest->id);
    }

    /** التحقق أن الحالة pending — وإلا 409 (US-044 السيناريو 4/5 · UC-06 E3). */
    private function assertPending(Interest $interest): void
    {
        if ($interest->status !== InterestStatus::PENDING) {
            throw new InvalidInterestStateException(
                $interest->status === InterestStatus::CANCELLED ? 'INTEREST_CANCELLED' : 'INVALID_INTEREST_STATUS',
                $interest->status === InterestStatus::CANCELLED ? __('interests.cancelled_error') : __('interests.invalid_status'),
            );
        }
    }

    /** فلترة الحالة القابلة للدمج: `pending,accepted` → whereIn. */
    private function applyStatusFilter($query, ?string $status): void
    {
        if ($status === null || $status === '') {
            return;
        }

        $statuses = array_values(array_filter(array_map('trim', explode(',', $status)), fn ($s) => $s !== ''));

        if ($statuses !== []) {
            $query->whereIn('interests.status', $statuses);
        }
    }

    /** عدد الصفحة: per_page (1..MAX_PAGE_SIZE) أو الافتراضي 12. */
    private function perPage(array $filters): int
    {
        $perPage = (int) ($filters['per_page'] ?? self::PAGE_SIZE);

        if ($perPage < 1) {
            return self::PAGE_SIZE;
        }

        return min($perPage, self::MAX_PAGE_SIZE);
    }

    /**
     * عدّادات الحالة — استعلام GROUP BY واحد على كل الصفوف (لا N+1، لا يتأثر
     * بالفلترة الحالية — تحديث عند التحميل · US-046 السيناريو 3).
     *
     * @return array{total: int, pending: int, accepted: int, rejected: int, cancelled: int}
     */
    private function counters($relation): array
    {
        // Query\Builder الأساسي (دون كاست النموذج — قيم status نصوص خام).
        $base = $relation->getQuery()->getQuery();

        $counters = [
            'total' => 0,
            'pending' => 0,
            'accepted' => 0,
            'rejected' => 0,
            'cancelled' => 0,
        ];

        // تأهيل العمود: interests.status — لأن جدول projects يحمل عمود status أيضاً
        // والاستعلام يجمع join بينهما (لا غموض في عمود).
        foreach ((clone $base)->selectRaw('interests.status, COUNT(*) as count')->groupBy('interests.status')->get() as $row) {
            if (array_key_exists($row->status, $counters)) {
                $counters[$row->status] = (int) $row->count;
            }
        }

        $counters['total'] = array_sum(array_slice($counters, 1));

        return $counters;
    }
}
