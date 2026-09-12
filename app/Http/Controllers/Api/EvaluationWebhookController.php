<?php

namespace App\Http\Controllers\Api;

use App\Services\External\EvaluationWebhookService;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvaluationWebhookController
{
    use ApiResponse;

    public function __construct(
        private readonly EvaluationWebhookService $webhooks,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $evaluation = $this->webhooks->apply($request->json()->all());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessable('INVALID_EVALUATION_WEBHOOK', $e->getMessage());
        }

        return $this->success([
            'evaluation_id' => $evaluation->id,
            'status' => $evaluation->status->value,
        ], 'webhook accepted');
    }
}