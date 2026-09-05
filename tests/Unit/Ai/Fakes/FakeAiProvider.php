<?php

namespace Tests\Unit\Ai\Fakes;

use App\Ai\Providers\AiProviderContract;
use App\Ai\Providers\AiResponse;
use App\Exceptions\Ai\ProviderException;

/**
 * مزوّد AI وهمي (plan.md §9.2 — Fakes) لاختبارات حتمية سريعة بلا شبكة.
 *
 * - `queue`: قائمة استجابات تُستهلك بالترتيب (كل استدعاء chat يزيح عنصراً).
 * - `failReason`: عند تعيينه يرمي ProviderException في كل استدعاء (لمحاكاة فشل المزوّد).
 * - `receivedMessages`: يسجّل كل رسائل الدردشة الواردة للفحص في الاختبارات.
 */
class FakeAiProvider implements AiProviderContract
{
    /** @var list<list<array{role: string, content: string}>> */
    public array $receivedMessages = [];

    public int $calls = 0;

    /**
     * @param  list<array<string, mixed>|string>  $queue  حمولات استجابات (مصفوفة أو نص JSON خام)
     * @param  string|null  $failReason  سبب فشل وهمي (network | 5xx | timeout | invalid_json | ...)
     */
    public function __construct(
        private array $queue = [],
        private ?string $failReason = null,
    ) {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function supportsStructuredOutput(): bool
    {
        return true;
    }

    public function chat(array $messages, array $options): AiResponse
    {
        $this->calls++;
        $this->receivedMessages[] = $messages;

        if ($this->failReason !== null) {
            throw new ProviderException($this->name(), 'Fake provider failure', $this->calls, $this->failReason);
        }

        $payload = array_shift($this->queue);

        if ($payload === null) {
            throw new ProviderException($this->name(), 'No fake response queued', $this->calls, 'network');
        }

        $content = is_string($payload)
            ? $payload
            : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new AiResponse(content: $content, model: 'fake-model', promptTokens: 10, completionTokens: 5, latencyMs: 1);
    }

    /**
     * حمولة استجابة بُعد صالحة (صيغة مخطط الاستجابة — plan.md §1.4).
     *
     * @param  array<string, float>  $subScores
     * @param  list<string>  $strengths
     * @param  list<string>  $weaknesses
     * @return array<string, mixed>
     */
    public static function response(
        float $score,
        array $subScores,
        array $strengths = [],
        array $weaknesses = [],
        ?float $confidence = 0.9,
        array $warnings = [],
    ): array {
        return [
            'score' => $score,
            'sub_scores' => $subScores,
            'strengths' => $strengths,
            'weaknesses' => $weaknesses,
            'confidence' => $confidence,
            'warnings' => $warnings,
        ];
    }
}
