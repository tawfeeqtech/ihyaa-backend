<?php

namespace App\Ai\Providers;

/**
 * استجابة موحّدة من مزوّد AI (plan.md §3.1 — SRS-AI-M03).
 * الحقول: content · model · promptTokens · completionTokens · latencyMs.
 * محتوى نصي/JSON فقط (لا HTML ولا صور) — التصيير في تطبيق Laravel.
 *
 * @immutable
 */
final class AiResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $model = '',
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
        public readonly int $latencyMs = 0,
    ) {
    }
}
