<?php

namespace App\Services\Analytics;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * EPIC-12 · US-064 — تصدير CSV للوحة التحليلات.
 *
 * العقد (admin-api.md §2): ثلاث أعمدة `section,metric,value` بتسميات إنجليزية.
 * المقاطع: users · projects · evaluation · sector · active_users · interests.
 * sector: metric = اسم المجال (عربي — name_ar) · active_users: metric = التاريخ.
 * BOM (\xEF\xBB\xBF) في بداية الملف — يفتحه Excel بترميز UTF-8 والعربية سليمة (T096).
 */
class AnalyticsCsvExporter
{
    /**
     * تدفّق تنزيل CSV — يُبنى من نفس مصفوفة analytics() (لا حساب مزدوج).
     *
     * @param  array<string, mixed>  $data  مخرجات AnalyticsService::analytics()
     */
    public function download(array $data): StreamedResponse
    {
        $filename = 'ihyaa-analytics-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');

            // BOM — UTF-8 byte-order mark (T096): بدونها يعرض Excel العربي مشوّهاً.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['section', 'metric', 'value']);

            // — users —
            fputcsv($out, ['users', 'total', $data['users']['total']]);
            foreach ($data['users']['by_role'] as $role => $count) {
                fputcsv($out, ['users', $role, $count]);
            }

            // — projects —
            fputcsv($out, ['projects', 'total', $data['projects']['total']]);
            fputcsv($out, ['projects', 'active', $data['projects']['active']]);
            fputcsv($out, ['projects', 'trashed', $data['projects']['trashed']]);
            foreach ($data['projects']['by_project_status'] as $status => $count) {
                fputcsv($out, ['projects', $status, $count]);
            }

            // — evaluation —
            fputcsv($out, ['evaluation', 'avg_ai_score', $data['avg_ai_score']]);

            // — sector (metric = اسم المجال بالعربية) —
            if (empty($data['sector_distribution'])) {
                fputcsv($out, ['sector', 'none', 0]);
            } else {
                foreach ($data['sector_distribution'] as $sector) {
                    fputcsv($out, ['sector', $sector['category'], $sector['count']]);
                }
            }

            // — active_users (metric = التاريخ Y-m-d) —
            foreach ($data['active_users_7d'] as $row) {
                fputcsv($out, ['active_users', $row['date'], $row['count']]);
            }

            // — interests —
            fputcsv($out, ['interests', 'total', $data['interests']['total']]);
            foreach (['pending', 'accepted', 'rejected', 'cancelled'] as $metric) {
                fputcsv($out, ['interests', $metric, $data['interests'][$metric] ?? 0]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
