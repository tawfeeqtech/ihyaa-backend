<?php

namespace App\Ai\Providers;

use App\Exceptions\Ai\ProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * مزوّد Claude (Anthropic) — plan.md §3.1 (SRS-AI-F01/F02).
 *
 * HTTP خام عبر `Illuminate\Http\Client` إلى `POST {base_url}/messages`:
 * ترويسات `x-api-key` + `anthropic-version: 2023-06-01` و `max_tokens` إلزامي.
 * لا حزمة SDK خارجية (YAGNI) — `supportsStructuredOutput() === false` في قاعدة MVP.
 *
 * قابل للضبط عبر `.env`: AI_CLAUDE_BASE_URL / AI_CLAUDE_API_KEY / AI_CLAUDE_MODEL.
 */
final class ClaudeProvider implements AiProviderContract
{
    private readonly array $config;

    /**
     * @param  array<string, mixed>  $config  يتجاوز config('ai.claude') عند التمرير (للاختبار)
     */
    public function __construct(array $config = [])
    {
        $this->config = $config !== [] ? $config : (array) config('ai.claude', []);
    }

    public function name(): string
    {
        return 'claude';
    }

    public function supportsStructuredOutput(): bool
    {
        return false;
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options  model · max_tokens · temperature · attempt
     *
     * @throws ProviderException عند فشل المزوّد (شبكة/مهلة/5xx/rate_limit/JSON غير صالح)
     */
    public function chat(array $messages, array $options): AiResponse
    {
        $attempt = (int) ($options['attempt'] ?? 1);
        $start = hrtime(true);

        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) ($this->config['api_key'] ?? ''),
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
                ->timeout((int) ($this->config['timeout'] ?? 45))
                ->connectTimeout(10)
                ->post($this->endpoint(), $this->buildPayload($messages, $options));
        } catch (ConnectionException $e) {
            throw $this->providerException($e, $attempt, 'timeout', 'Claude connection timed out.');
        } catch (Throwable $e) {
            throw $this->providerException($e, $attempt, 'network', 'Claude network request failed.');
        }

        $status = $response->status();

        if ($status >= 400) {
            throw $this->providerException(
                null,
                $attempt,
                $this->reasonForStatus($status),
                sprintf('Claude API returned HTTP %d.', $status)
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new ProviderException(
                'claude',
                'Claude returned a non-JSON payload.',
                attempt: $attempt,
                reason: 'invalid_json'
            );
        }

        $content = $this->extractText($data);

        if ($content === '') {
            throw new ProviderException(
                'claude',
                'Claude returned an empty message content.',
                attempt: $attempt,
                reason: 'invalid_json'
            );
        }

        $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);

        return new AiResponse(
            content: $content,
            model: (string) ($data['model'] ?? ($this->config['model'] ?? '')),
            promptTokens: (int) ($data['usage']['input_tokens'] ?? 0),
            completionTokens: (int) ($data['usage']['output_tokens'] ?? 0),
            latencyMs: $latencyMs,
        );
    }

    private function endpoint(): string
    {
        $baseUrl = rtrim((string) ($this->config['base_url'] ?? 'https://api.anthropic.com/v1'), '/');

        return $baseUrl . '/messages';
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     *
     * @return array<string, mixed>
     */
    private function buildPayload(array $messages, array $options): array
    {
        $system = '';
        $claudeMessages = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');
            $content = (string) ($message['content'] ?? '');

            if ($role === 'system') {
                $system .= ($system === '' ? '' : "\n\n") . $content;
                continue;
            }

            $claudeMessages[] = [
                'role' => $role === 'assistant' ? 'assistant' : 'user',
                'content' => $content,
            ];
        }

        $payload = [
            'model' => (string) ($options['model'] ?? ($this->config['model'] ?? 'claude-3-5-haiku-latest')),
            'max_tokens' => (int) ($options['max_tokens'] ?? 1024),
            'messages' => $claudeMessages,
        ];

        if ($system !== '') {
            $payload['system'] = $system;
        }

        if (array_key_exists('temperature', $options) && $options['temperature'] !== null) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractText(array $data): string
    {
        $blocks = $data['content'] ?? [];

        if (! is_array($blocks)) {
            return '';
        }

        $text = '';

        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && isset($block['text'])) {
                $text .= (string) $block['text'];
            }
        }

        return $text;
    }

    private function reasonForStatus(int $status): string
    {
        return match (true) {
            $status === 429 => 'rate_limited',
            $status === 408, $status === 425, $status === 504 => 'timeout',
            $status >= 500 => '5xx',
            default => 'api_error',
        };
    }

    private function providerException(
        ?Throwable $previous,
        int $attempt,
        string $reason,
        string $message,
    ): ProviderException {
        return new ProviderException(
            'claude',
            $message,
            attempt: $attempt,
            reason: $reason,
            context: ['status' => $previous ? $previous->getCode() : null],
            previous: $previous,
        );
    }
}
