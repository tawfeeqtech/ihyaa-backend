<?php

namespace App\Http\Controllers\Api;

use App\Services\Analytics\AnalyticsCsvExporter;
use App\Services\Analytics\AnalyticsService;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * EPIC-12 · US-061..064 — تحليلات المشرف.
 *
 * admin-api.md §0: الأرقام تُحسب مباشرة من قاعدة البيانات عند الطلب.
 * التوجيه خلف middleware `admin` (دور ADMIN — يُنشأ بالسيدر فقط، الدستور IV)
 * + Rate Limiters: admin.analytics (30/دقيقة) · admin.export (10/دقيقة).
 */
class AdminAnalyticsController
{
    use ApiResponse;

    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly AnalyticsCsvExporter $exporter,
    ) {}

    /** GET /api/admin/analytics — لوحة الإحصائيات (SRS-API-40 · US-061). */
    public function analytics(): JsonResponse
    {
        return $this->success($this->analytics->analytics());
    }

    /** GET /api/admin/analytics/export — تصدير CSV (SRS-API-41 · US-064). */
    public function export(): StreamedResponse
    {
        return $this->exporter->download($this->analytics->analytics());
    }
}
