<?php

namespace App\Ai\Providers;

use App\Enums\ModelUsed;
use App\Models\AiRequestLog;
use InvalidArgumentException;

/**
 * مسجّل طلبات مزوّدي AI للمعايرة — plan.md §3.4 (FR-207 / SRS-TEST-AI-11).
 *
 * يكتب إلى `ai_request_logs` **معرّفات ومقاييس فقط**:
 * provider · model · attempt · success · latency_ms · prompt/completion tokens ·
 * failure_reason · fallback_reason · consensus_round.
 *
 * قيد صارم (المبدأ V / SRS-AI-M04): لا يُكتب محتوى المشروع إطلاقاً —
 * لا وصف، لا أسماء أشخاص، لا روابط GitHub كاملة. أي مفتاح خارج القائمة
 * البيضاء أدناه يُتجاهل بصمت (تعقيم عند الحدود).
 */
final class AiRequestLogger
{
    /**
     * الحقول الوحيدة المسموح بكتابتها في الجدول.
     *
     * @var list<string>
     */
    public const ALLOWED_FIELDS = [
        'evaluation_id',
        'project_id',
        'dimension',
        'provider',
        'model',
        'attempt',
        'success',
        'latency_ms',
        'prompt_tokens',
        'completion_tokens',
        'failure_reason',
        'fallback_reason',
        'consensus_round',
    ];

    /**
     * @var list<string>
     */
    private const VALID_PROVIDERS = ['openai', 'claude'];

    /**
     * تسجيل طلب AI واحد.
     *
     * @param  array<string, mixed>  $data  معرّفات ومقاييس فقط — أي مفتاح خارج القائمة البيضاء يُتجاهل.
     *
     * @throws InvalidArgumentException عند مزوّد غير معروف أو غياب success.
     */
    public function log(array $data): AiRequestLog
    {
        return AiRequestLog::create($this->sanitize($data));
    }

    /**
     * تعقيم عند الحدود: قائمة بيضاء + تطبيع الأنواع.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        $row = [];

        foreach (self::ALLOWED_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $row[$field] = $data[$field];
            }
        }

        // تطبيع المزوّد (يدعم ModelUsed enum أو نصاً).
        $provider = $row['provider'] ?? null;
        if ($provider instanceof ModelUsed) {
            $provider = $provider->value;
        }
        if (! in_array($provider, self::VALID_PROVIDERS, true)) {
            throw new InvalidArgumentException(
                sprintf('Invalid AI provider [%s]. Expected one of: openai, claude.', (string) $provider)
            );
        }
        $row['provider'] = $provider;

        if (! array_key_exists('success', $row)) {
            throw new InvalidArgumentException('AiRequestLogger requires a [success] boolean.');
        }

        $row['model'] = (string) ($row['model'] ?? '');
        $row['attempt'] = (int) ($row['attempt'] ?? 1);
        $row['success'] = (bool) $row['success'];
        $row['latency_ms'] = isset($row['latency_ms']) ? (int) $row['latency_ms'] : null;
        $row['prompt_tokens'] = isset($row['prompt_tokens']) ? (int) $row['prompt_tokens'] : null;
        $row['completion_tokens'] = isset($row['completion_tokens']) ? (int) $row['completion_tokens'] : null;
        $row['failure_reason'] = isset($row['failure_reason']) ? (string) $row['failure_reason'] : null;
        $row['fallback_reason'] = isset($row['fallback_reason']) ? (string) $row['fallback_reason'] : null;
        $row['consensus_round'] = (bool) ($row['consensus_round'] ?? false);

        return $row;
    }
}
