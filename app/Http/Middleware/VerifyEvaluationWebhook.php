<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyEvaluationWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $timestamp = (string) $request->header('X-Webhook-Timestamp', '');
        $signature = (string) $request->header('X-Webhook-Signature', '');
        $secret = (string) config('services.python_evaluation.webhook_secret', '');
        $tolerance = (int) config('services.python_evaluation.webhook_tolerance_seconds', 300);

        if ($timestamp === '' || $signature === '' || $secret === '' || ! ctype_digit($timestamp)) {
            return $this->unauthorized();
        }

        if (abs(time() - (int) $timestamp) > $tolerance) {
            return $this->unauthorized();
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            return $this->unauthorized();
        }

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'success' => false,
            'code' => 'INVALID_WEBHOOK_SIGNATURE',
            'message' => 'Invalid evaluation webhook signature.',
            'errors' => null,
        ], 401);
    }
}