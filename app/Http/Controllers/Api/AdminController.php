<?php

namespace App\Http\Controllers\Api;

use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * لوحة المشرف — SRS-API-40/41 · L6 (Admin — seeder فقط).
 * analytics: إحصائيات المنصة (SRS-F12) · export: تصدير CSV.
 */
class AdminController
{
    use ApiResponse;

    /** RL-ADM-01 · 60/دقيقة */
    public function analytics(Request $request): JsonResponse
    {
        $stats = app(DashboardController::class)->adminStats();

        return $this->success($stats);
    }

    /** RL-ADM-02 · 10/دقيقة — تصدير CSV */
    public function export(Request $request): StreamedResponse
    {
        $stats = app(DashboardController::class)->adminStats();

        $rows = [
            ['metric', 'value'],
            ['users.total', $stats['users']['total']],
            ['users.idea_owners', $stats['users']['idea_owners']],
            ['users.investors', $stats['users']['investors']],
            ['users.active_7d', $stats['users']['active_7d']],
            ['projects.total', $stats['projects']['total']],
            ['projects.published', $stats['projects']['published']],
            ['projects.trashed', $stats['projects']['trashed']],
            ['projects.avg_ai_score', $stats['projects']['avg_ai_score']],
            ['evaluations.total', $stats['evaluations']['total']],
            ['evaluations.completed', $stats['evaluations']['completed']],
            ['evaluations.failed', $stats['evaluations']['failed']],
            ['interests.total', $stats['interests']['total']],
            ['interests.accepted', $stats['interests']['accepted']],
        ];

        foreach ($stats['projects']['by_category'] as $category) {
            $rows[] = ['projects.category.'.$category['slug'], $category['count']];
        }

        return response()->streamDownload(function () use ($rows) {
            $stream = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($stream, $row);
            }
            fclose($stream);
        }, 'ihyaa-analytics-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
