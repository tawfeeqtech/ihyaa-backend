<?php

namespace App\Http\Controllers\Api;

use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * الصحة والجاهزية — L7 · بلا Rate Limit (IP داخلي فقط في الإنتاج).
 */
class HealthController
{
    use ApiResponse;

    /** liveness — التطبيق يعمل */
    public function index(): JsonResponse
    {
        return $this->success([
            'status' => 'ok',
            'service' => 'ihyaa-api',
            'time' => now()->toISOString(),
        ]);
    }

    /** readiness — قاعدة البيانات متاحة */
    public function ready(): JsonResponse
    {
        $db = false;

        try {
            $db = DB::connection()->getPdo() !== null;
        } catch (\Throwable) {
            $db = false;
        }

        if (! $db) {
            return response()->json([
                'success' => false,
                'status' => 'not_ready',
                'checks' => ['database' => false],
            ], 503);
        }

        return $this->success([
            'status' => 'ready',
            'checks' => ['database' => true],
        ]);
    }
}
