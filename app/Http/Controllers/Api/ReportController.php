<?php

namespace App\Http\Controllers\Api;

use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\ReportExportLog;
use App\Services\Disclosure\DisclosureService;
use App\Services\Reports\PdfReportService;
use App\Services\Reports\ReportDataService;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * تقرير AI: البيانات (JSON) والتصدير (PDF) — contracts/report-api.md (T091/T107).
 *
 * | النقطة | الحالة |
 * |---|---|
 * | GET /projects/{project}/evaluations/{evaluation} | 200 بيانات التقرير حسب مصفوفة الإفصاح (US-029) |
 * | GET /projects/{project}/evaluations/{evaluation}/report | 200 PDF · 403 PDF_EXPORT_DENIED · 500 فشل المحرك |
 *
 * الحماية الفعلية على الخادم (المبدأ الدستوري I): كل قراءة تمر عبر
 * ReportDataService → DisclosureService::shapeFor (PEP وحيد)، وكل تصدير عبر
 * EvaluationPolicy::viewFullReport. أي طلب حقل محمي يُرفض 403 في EnforceReportDisclosure.
 */
class ReportController
{
    use ApiResponse;

    public function __construct(
        private readonly ReportDataService $reportData,
        private readonly PdfReportService $pdf,
        private readonly DisclosureService $disclosure,
    ) {
    }

    /** GET /api/projects/{project}/evaluations/{evaluation} — بيانات التقرير (T091 · US-025/029) */
    public function show(Request $request, Project $project, Evaluation $evaluation): JsonResponse
    {
        // 404 لتقييمات بلا تقرير (لا يتسرب محتوى تقرير غير مكتمل).
        if (! $this->hasReport($evaluation)) {
            return $this->notFound();
        }

        // التقييم لا يخص هذا المشروع — 404 (لا نكشف وجود تقييم مسار آخر).
        if ((int) $evaluation->project_id !== (int) $project->id) {
            return $this->notFound();
        }

        // مشروع في السلة — لا يُعرض تقريره (Edge Case — report-api.md §1).
        if ($project->trashed()) {
            return $this->notFound();
        }

        $data = $this->reportData->get($evaluation, $project, $request->user());

        return $this->success($data);
    }

    /** GET /api/projects/{project}/evaluations/{evaluation}/report — تصدير PDF (T107 · US-028) */
    public function export(Request $request, Project $project, Evaluation $evaluation): Response|JsonResponse
    {
        $user = $request->user();

        // 404: التقييم لا يخص المشروع أو مشروع محذوف (سلة) أو تقييم بلا تقرير.
        if ((int) $evaluation->project_id !== (int) $project->id || $project->trashed() || ! $this->hasReport($evaluation)) {
            return $this->notFound();
        }

        $lang = $request->query('lang') === 'en' ? 'en' : 'ar';

        // التفويض: L3/EX/AD فقط (نفس EvaluationPolicy::viewFullReport) — أي مستوى أدنى 403.
        if (! $user || ! Gate::allows('viewFullReport', $evaluation)) {
            $this->log($evaluation, $user?->id, $lang, 'denied', $project);

            return $this->error(
                'PDF_EXPORT_DENIED',
                'تصدير التقرير متاح لصاحب المشروع أو بعد الاتفاق فقط',
                403,
            );
        }

        $level = $this->disclosure->resolveLevel($user, $project)->value;

        try {
            $pdf = $this->pdf->generate($evaluation, $project, $lang, $user);
        } catch (\Throwable $e) {
            $this->log($evaluation, $user->id, $lang, 'failed', $project);

            return $this->error(
                'PDF_GENERATION_FAILED',
                'تعذّر إنشاء التقرير — حاول مجدداً',
                500,
            );
        }

        $this->log($evaluation, $user->id, $lang, 'success', $project, $level);

        $fileName = 'evaluation-report-'.$evaluation->id.'-'.$lang.'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            'Content-Length' => (string) strlen($pdf),
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    // ——————————————————————— أدوات ———————————————————————

    /** هل للتقييم تقرير قابل للعرض/التصدير؟ (completed أو partial فقط) */
    private function hasReport(Evaluation $evaluation): bool
    {
        return in_array($evaluation->status, [EvaluationStatus::COMPLETED, EvaluationStatus::PARTIAL], true);
    }

    /**
     * تسجيل طلب التصدير في report_export_logs — من/متى/أي تقييم/المستوى/الحالة
     * دون محتوى التقرير (T109 · US-028-S5).
     */
    private function log(Evaluation $evaluation, ?int $userId, string $lang, string $status, Project $project, ?string $level = null): void
    {
        try {
            ReportExportLog::create([
                'evaluation_id' => $evaluation->id,
                'user_id' => $userId ?? 0,
                'access_level' => $level ?? $this->disclosure->resolveLevel(auth()->user(), $project)->value,
                'language' => $lang,
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            // فشل التسجيل لا يُفشل التصدير — سجل خطأ فقط (التدقيق تكميلي).
            Log::warning('report_export_log write failed', [
                'evaluation_id' => $evaluation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
