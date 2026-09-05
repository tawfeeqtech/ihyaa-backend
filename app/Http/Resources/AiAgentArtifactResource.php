<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * T116 — مورد مخرجات وكيل AI (العرض: التحليل + الإصدار + الحالة + الخطأ).
 */
class AiAgentArtifactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'analysis_type' => $this->analysis_type?->value,
            'artifact_data' => $this->artifact_data,
            'version' => $this->version,
            'status' => $this->status?->value,
            'model_used' => $this->model_used?->value,
            'language' => $this->language,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
