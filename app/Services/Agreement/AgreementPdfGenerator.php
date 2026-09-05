<?php

namespace App\Services\Agreement;

use App\Models\Interest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;

/**
 * T050 — توليد مستند الاتفاق الثابت PDF (US-045 · FR-310).
 *
 * mPDF مع خط Amiri + autoScriptToLang/autoLangToFont (عربي RTL سليم —
 * نفس إعدادات EPIC-05 PdfReportService). القالب Blade RTL:
 * resources/views/agreements/agreement.blade.php (T049).
 *
 * المسار: agreements/agreement-{interest_id}-{investor_id}-{Y-m-d}.pdf على
 * Storage::disk('public') — Local Disk (لا S3 في MVP).
 *
 * عند الفشل تُرمى RuntimeException ويُسجَّل الخطأ — يلتقطها AgreementService
 * (T051) لتحويل الطلب إلى accepted_pending_document (T052).
 */
class AgreementPdfGenerator
{
    public function generate(Interest $interest): string
    {
        try {
            $project = $interest->project;
            $owner = $project->owner;
            $investor = $interest->investor;

            $mpdf = new Mpdf($this->mpdfConfig());
            $mpdf->SetTitle(__('agreement.pdf_title', ['app' => config('app.name', 'Ihyaa')]));

            $html = view('agreements.agreement', [
                'interest' => $interest,
                'project' => $project,
                'owner' => $owner,
                'investor' => $investor,
            ])->render();

            $mpdf->WriteHTML($html);

            $pdf = (string) $mpdf->Output('', 'S');

            $path = 'agreements/agreement-'.$interest->id.'-'.$investor->id.'-'.now()->format('Y-m-d').'.pdf';
            Storage::disk('public')->put($path, $pdf);

            return $path;
        } catch (\Throwable $e) {
            Log::error('Agreement PDF generation failed', [
                'interest_id' => $interest->id,
                'project_id' => $interest->project_id,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('تعذّر إنشاء مستند الاتفاق — سيُعاد توليده تلقائياً', 0, $e);
        }
    }

    /** إعدادات mPDF — عربي RTL (Amiri) — مطابقة لـ PdfReportService (EPIC-05). */
    private function mpdfConfig(): array
    {
        return [
            'mode' => config('pdf.mode', 'utf-8'),
            'format' => config('pdf.format', 'A4'),
            'default_font_size' => (float) config('pdf.default_font_size', 11),
            'default_font' => 'amiri',
            'margin_left' => (int) config('pdf.margin_left', 14),
            'margin_right' => (int) config('pdf.margin_right', 14),
            'margin_top' => (int) config('pdf.margin_top', 14),
            'margin_bottom' => (int) config('pdf.margin_bottom', 16),
            'autoScriptToLang' => (bool) config('pdf.auto_script_to_lang', true),
            'autoLangToFont' => (bool) config('pdf.auto_lang_to_font', true),
            'use_kwt' => (bool) config('pdf.use_kwt', true),
            'keep_table_proportions' => (bool) config('pdf.keep_table_proportions', true),
            'shrink_tables_to_fit' => (float) config('pdf.shrink_tables_to_fit', 1.4),
            'fontDir' => (array) config('pdf.font_dir', []),
            'fontdata' => (array) config('pdf.font_data', []),
        ];
    }
}
