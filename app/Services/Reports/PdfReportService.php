<?php

namespace App\Services\Reports;

use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use App\Services\Disclosure\DisclosureService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mpdf\Mpdf;

/**
 * توليد تقرير AI PDF — contracts/report-api.md §2 (T105/T107 · US-028).
 *
 * - التفويض: يُطبَّق قبل الاستدعاء (EvaluationPolicy::viewFullReport / L3/EX/AD).
 * - RTL عربي: mPDF + Amiri + autoScriptToLang/autoLangToFont (US-028-S4).
 * - التقرير الجزئي: يُصدَّر مع تحذير بالأبعاد الناقصة (لا رفض — Edge Case).
 * - كاش: Cache::remember('pdf:report:{id}:{lang}', 3600) — يولَّد مرة واحدة (US-028 §2).
 * - الفشل: MpdfException يُسجَّل ويُعاد 500 برسالة "تعذّر إنشاء التقرير" (Edge Case).
 */
class PdfReportService
{
    public function __construct(
        private readonly DisclosureService $disclosure,
        private readonly RadarSvgRenderer $radar,
    ) {
    }

    /**
     * توليد/قراءة التقرير PDF (مخزَّن مؤقتاً).
     *
     * @param  User|null  $user  المستخدم المصادق — وصوله مُتحقق منه (viewFullReport) قبل الاستدعاء
     * @throws \RuntimeException  عند فشل محرك PDF (يُسجَّل داخلياً)
     */
    public function generate(Evaluation $evaluation, Project $project, string $lang, ?User $user = null): string
    {
        $lang = $lang === 'en' ? 'en' : 'ar';

        return Cache::remember(
            'pdf:report:'.$evaluation->id.':'.$lang,
            (int) config('pdf.cache_seconds', 3600),
            fn (): string => $this->render($evaluation, $project, $lang, $user),
        );
    }

    /**
     * توليد PDF فعلي (بدون كاش) — يُستخدم عند الحاجة لإجبار إعادة التوليد.
     *
     * @param  User|null  $user  المستخدم المصادق — وصوله مُتحقق منه (viewFullReport) قبل الاستدعاء
     */
    public function render(Evaluation $evaluation, Project $project, string $lang, ?User $user = null): string
    {
        try {
            $report = $this->disclosure->shapeFor($evaluation, $project, $user);
            $radarSvg = $this->radar->render($report['radar_chart']['axes'] ?? []);

            $mpdf = new Mpdf($this->mpdfConfig($lang));
            $mpdf->SetTitle($this->title($project, $evaluation, $lang));

            $html = view('pdf.report', [
                'project' => $project,
                'evaluation' => $evaluation,
                'report' => $report,
                'radarSvg' => $radarSvg,
                'lang' => $lang,
            ])->render();

            $mpdf->WriteHTML($html);

            return (string) $mpdf->Output('', 'S');
        } catch (\Throwable $e) {
            Log::error('PDF report generation failed', [
                'evaluation_id' => $evaluation->id,
                'project_id' => $project->id,
                'lang' => $lang,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('تعذّر إنشاء التقرير — حاول مجدداً', 0, $e);
        }
    }

    // ——————————————————————— أدوات ———————————————————————

    /** إعدادات mPDF حسب اللغة — عربي RTL (Amiri) مقابل إنجليزي LTR (DejaVu). */
    private function mpdfConfig(string $lang): array
    {
        return [
            'mode' => config('pdf.mode', 'utf-8'),
            'format' => config('pdf.format', 'A4'),
            'default_font_size' => (float) config('pdf.default_font_size', 11),
            'default_font' => $lang === 'ar' ? 'amiri' : 'dejavusans',
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

    private function title(Project $project, Evaluation $evaluation, string $lang): string
    {
        $suffix = $lang === 'ar' ? 'تقرير التقييم — '.$project->title : 'AI Evaluation Report — '.$project->title;

        return 'Ihyaa — '.$suffix.' (v'.$evaluation->version.')';
    }
}
