<?php

namespace App\Http\Controllers\Api;

use App\Services\Project\TagSuggestionService;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * اقتراحات الوسوم — SRS-API-49 · RL-PUB-01 (30/دقيقة · IP).
 * حتى 10 اقتراحات — تُستخرج من وسوم المشاريع المنشورة (Cache 10 دقائق).
 *
 * T160: المنطق في TagSuggestionService · T166: الرد data بدل suggestions (contract).
 */
class TagController
{
    use ApiResponse;

    public function __construct(private readonly TagSuggestionService $tags)
    {
    }

    public function suggestions(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:50'],
        ]);

        $term = strtolower((string) $request->input('q', ''));

        // T166: الرد data مباشرةً (contract §tags) — success() يغلّف الحمولة داخل data تلقائياً
        return $this->success($this->tags->suggestions($term));
    }
}
