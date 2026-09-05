<?php

namespace App\Services\Agreement;

use App\Enums\InterestStatus;
use App\Exceptions\Interest\InvalidInterestStateException;
use App\Models\Agreement;
use App\Models\Interest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * T051 — معاملة القبول وإنشاء مستند الاتفاق (US-045 · FR-310 · contract §5).
 *
 * معاملة واحدة داخل DB::transaction + lockForUpdate (منع قبولين متوازيين —
 * T045). التسلسل: التحقق pending ← توليد PDF ← إدراج agreements ← ربط
 * interest.agreement_id ← state=accepted + accepted_at (كشف البريد في
 * InterestResource). عند فشل PDF مؤقت: accepted_pending_document + جدولة
 * RetryPendingDocuments (T052) — ضمن نفس المعاملة.
 */
class AgreementService
{
    public function __construct(
        private readonly AgreementPdfGenerator $pdf,
    ) {
    }

    /**
     * قبول الطلب وإنشاء الاتفاق (معاملة واحدة).
     *
     * @throws InvalidInterestStateException  الحالة ليست pending (409)
     */
    public function accept(Interest $interest): Interest
    {
        return DB::transaction(function () use ($interest) {
            // قفل الصف — يمنع قبولين متوازيين لنفس الطلب (US-044 السيناريو 4).
            $locked = Interest::query()->lockForUpdate()->findOrFail($interest->id);

            if ($locked->status !== InterestStatus::PENDING) {
                throw new InvalidInterestStateException(
                    $locked->status === InterestStatus::CANCELLED ? 'INTEREST_CANCELLED' : 'INVALID_INTEREST_STATUS',
                    $locked->status === InterestStatus::CANCELLED ? __('interests.cancelled_error') : __('interests.invalid_status'),
                );
            }

            // توليد PDF — قد يفشل مؤقتاً (محرك خارجي/ملفات).
            try {
                $pdfPath = $this->pdf->generate($locked);
            } catch (\Throwable $e) {
                // T052 — فشل PDF مؤقت: حالة وسيطة + إعادة محاولة خلفية بعد 5 دقائق.
                Log::warning('Agreement accept deferred (pdf failed)', [
                    'interest_id' => $locked->id,
                    'error' => $e->getMessage(),
                ]);

                $locked->forceFill([
                    'status' => InterestStatus::ACCEPTED_PENDING_DOCUMENT,
                    'accepted_at' => now(),
                ])->save();

                RetryPendingDocuments::dispatch($locked->id)->delay(now()->addMinutes(5));

                return $locked;
            }

            $agreement = Agreement::create([
                'interest_id' => $locked->id,
                'idea_owner_id' => $locked->project->user_id,
                'investor_id' => $locked->investor_id,
                'project_id' => $locked->project_id,
                'pdf_path' => $pdfPath,
                'idea_owner_name' => $locked->project->owner->name,
                'investor_name' => $locked->investor->name,
            ]);

            $locked->forceFill([
                'status' => InterestStatus::ACCEPTED,
                'agreement_id' => $agreement->id,
                'agreement_pdf_path' => $pdfPath,
                'accepted_at' => now(),
            ])->save();

            return $locked;
        });
    }
}
