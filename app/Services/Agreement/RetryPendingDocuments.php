<?php

namespace App\Services\Agreement;

use App\Enums\InterestStatus;
use App\Enums\UserRole;
use App\Models\Agreement;
use App\Models\Interest;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * T052 — إعادة محاولة توليد مستند PDF للطلبات المعلقة بانتظار المستند (FR-310).
 *
 * عند فشل PDF مؤقتاً أثناء القبول: InterestService ينتقل بالطلب إلى
 * accepted_pending_document ويُرسل هذه المهمة مؤجلة 5 دقائق.
 *
 * - tries = 3 (إجمالي المحاولات عبر $this->attempts()).
 * - نجاح المحاولة: يُنشأ الاتفاق + state=accepted + إشعار interest_accepted.
 * - فشل نهائي (failed): يبقى الطلب accepted بلا مستند + إشعار pdf_generation_failed
 *   للمشرف (UC-07 A1/E1).
 * - فريد: retry-pdf:{interest_id} يمنع تكديس محاولات مكررة.
 */
class RetryPendingDocuments implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** 5 دقائق بين محاولات إعادة التوليد (إجمالاً حتى 3 محاولات خلال ~15 دقيقة). */
    public array $backoff = [300, 300];

    public int $uniqueFor = 30;

    public function __construct(public int $interestId)
    {
        $this->onQueue('agreements');
    }

    public function uniqueId(): string
    {
        return 'retry-pdf:'.$this->interestId;
    }

    public function handle(AgreementPdfGenerator $pdf): void
    {
        $interest = Interest::find($this->interestId);

        // لا شيء: أُلغي الطلب أو عولج بالفعل.
        if (! $interest || $interest->status !== InterestStatus::ACCEPTED_PENDING_DOCUMENT) {
            return;
        }

        try {
            $pdfPath = $pdf->generate($interest);

            $agreement = Agreement::create([
                'interest_id' => $interest->id,
                'idea_owner_id' => $interest->project->user_id,
                'investor_id' => $interest->investor_id,
                'project_id' => $interest->project_id,
                'pdf_path' => $pdfPath,
                'idea_owner_name' => $interest->project->owner->name,
                'investor_name' => $interest->investor->name,
            ]);

            $interest->forceFill([
                'status' => InterestStatus::ACCEPTED,
                'agreement_id' => $agreement->id,
                'agreement_pdf_path' => $pdfPath,
            ])->save();

            Notification::pushNotification(
                $interest->investor_id,
                'interest_accepted',
                __('notifications.interest_accepted_title', ['project' => $interest->project->title]),
                null,
                ['project_id' => $interest->project_id, 'interest_id' => $interest->id, 'url' => '/projects/'.$interest->project_id],
                false,
            );
        }
        // أي استثناء يتدفق للـ queue → إعادة محاولة تلقائية (backoff 300s × 2) ثم failed().
    }

    /** الفشل النهائي بعد 3 محاولات — إشعار للمشرف + يبقى الطلب accepted بلا مستند. */
    public function failed(\Throwable $e): void
    {
        $interest = Interest::find($this->interestId);

        if (! $interest) {
            return;
        }

        // انتقال النظام: accepted_pending_document → accepted (بلا مستند — UC-07 A1/E1).
        $interest->forceFill(['status' => InterestStatus::ACCEPTED])->save();

        $admins = User::query()->where('role', UserRole::ADMIN)->get();

        foreach ($admins as $admin) {
            Notification::pushNotification(
                $admin->id,
                'pdf_generation_failed',
                __('notifications.pdf_generation_failed_title', ['project' => $interest->project->title]),
                __('notifications.pdf_generation_failed_body', ['interest' => $interest->id]),
                ['project_id' => $interest->project_id, 'interest_id' => $interest->id],
                false,
            );
        }
    }
}
