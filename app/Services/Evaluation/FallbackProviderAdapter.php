<?php

namespace App\Services\Evaluation;

use App\Ai\Providers\AiProviderContract;
use App\Ai\Providers\AiResponse;
use App\Ai\Providers\FallbackManager;

/**
 * محوّل يقدّم FallbackManager كـ AiProviderContract واحد (plan.md §3.2 — SRS-AI-F01/F02).
 *
 * Sub-Agent يستدعي `$provider->chat(...)` على مزوّد واحد؛ هذا المحوّل يعيد توجيه
 * الاستدعاء إلى FallbackManager (تحويل ثنائي الاتجاه + إعادة محاولات 0/3/9s داخلية).
 *
 * @final
 */
final class FallbackProviderAdapter implements AiProviderContract
{
    public function __construct(
        private readonly FallbackManager $fallback,
    ) {
    }

    public function name(): string
    {
        return 'fallback';
    }

    public function supportsStructuredOutput(): bool
    {
        return true;
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function chat(array $messages, array $options): AiResponse
    {
        return $this->fallback->withFallback(
            fn (AiProviderContract $provider) => $provider->chat($messages, $options),
            $this->logContext($options),
        );
    }

    /**
     * سياق سجل المعايرة — معرّفات ومقاييس فقط (المبدأ V / SRS-AI-M04).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function logContext(array $options): array
    {
        return [
            'evaluation_id' => $options['evaluation_id'] ?? null,
            'project_id' => $options['project_id'] ?? null,
            'dimension' => $options['dimension'] ?? null,
            'consensus_round' => (bool) ($options['consensus_round'] ?? false),
        ];
    }
}
