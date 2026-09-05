<?php

namespace App\Services\AI;

use App\Models\AiAgentArtifact;
use Illuminate\Support\Facades\Log;
use Mpdf\Mpdf;
use RuntimeException;

/**
 * T118 — تصدير تقرير وكيل AI إلى PDF (US-080..084 · SRS-API-43).
 *
 * mPDF مع خط Amiri + autoScriptToLang/autoLangToFont (عربي RTL سليم) — نفس
 * إعدادات AgreementPdfGenerator/PdfReportService. اتجاه الصفحة حسب
 * artifact.language (ar → rtl | en → ltr). يُرمى RuntimeException عند الفشل
 * (يُلتقط في الـ Controller → 409 ANALYSIS_INCOMPLETE أو 500 مسجّل).
 */
class AgentReportPdfExporter
{
    public function export(AiAgentArtifact $artifact): string
    {
        try {
            $language = (string) ($artifact->language ?? 'ar');
            $project = $artifact->project;

            $mpdf = new Mpdf($this->mpdfConfig());
            $mpdf->SetTitle(__('ai_agent.pdf_title', ['app' => config('app.name', 'Ihyaa')]));

            $html = view('ai_agent.report', [
                'artifact' => $artifact,
                'project' => $project,
                'data' => $artifact->artifact_data ?? [],
                'language' => $language,
            ])->render();

            $mpdf->WriteHTML($html);

            return (string) $mpdf->Output('', 'S');
        } catch (\Throwable $e) {
            Log::error('AI Agent PDF export failed', [
                'artifact_id' => $artifact->id,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('تعذّر تصدير تقرير التحليل PDF', 0, $e);
        }
    }

    /** إعدادات mPDF — عربي RTL (Amiri) — مطابقة لـ AgreementPdfGenerator (T050). */
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
