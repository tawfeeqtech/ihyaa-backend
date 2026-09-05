<?php

namespace App\Ai\Providers;

use App\Exceptions\Ai\ProviderException;
use GuzzleHttp\Client as GuzzleClient;
use OpenAI\Client;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Exceptions\UnserializableResponse;
use OpenAI\Factory;
use Throwable;

/**
 * مزوّد OpenAI — plan.md §3.1 (SRS-AI-F01/F02).
 *
 * يستخدم `openai-php/client` (v0.20) مع `response_format` لإخراج JSON منظم
 * (يقصّر دورة التحقق من JSON). مهلة الطلب 45s عبر عميل Guzzle مهرّأ.
 *
 * قابل للضبط عبر `.env`: AI_OPENAI_BASE_URL / AI_OPENAI_API_KEY / AI_OPENAI_MODEL.
 */
final class OpenAiProvider implements AiProviderContract
{
    private readonly Client $client;

    private readonly array $config;

    /**
     * @param  array<string, mixed>  $config  يتجاوز config('ai.openai') عند التمرير (للاختبار)
     */
    public function __construct(
        ?Client $client = null,
        array $config = [],
    ) {
        $this->config = $config !== [] ? $config : (array) config('ai.openai', []);
        $this->client = $client ?? $this->buildClient();
    }

    public function name(): string
    {
        return 'openai';
    }

    public function supportsStructuredOutput(): bool
    {
        return true;
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options  model · temperature · max_tokens · response_format · json_schema · attempt
     *
     * @throws ProviderException عند فشل المزوّد (شبكة/مهلة/5xx/rate_limit/JSON غير صالح)
     */
    public function chat(array $messages, array $options): AiResponse
    {
        $attempt = (int) ($options['attempt'] ?? 1);
        $start = hrtime(true);

        try {
            $response = $this->client->chat()->create($this->buildPayload($messages, $options));
        } catch (ErrorException $e) {
            throw $this->providerException(
                $e,
                $attempt,
                $this->reasonForStatus($e->getStatusCode() ?? 0),
                sprintf('OpenAI API error: %s', $e->getErrorMessage() ?: $e->getMessage())
            );
        } catch (TransporterException $e) {
            throw $this->providerException(
                $e,
                $attempt,
                $this->reasonForTransportException($e),
                'OpenAI transport failure.'
            );
        } catch (UnserializableResponse $e) {
            throw $this->providerException($e, $attempt, 'invalid_json', 'OpenAI returned an unserializable payload.');
        } catch (Throwable $e) {
            throw $this->providerException($e, $attempt, 'network', 'OpenAI request failed.');
        }

        $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);

        $content = $response->choices[0]->message->content ?? '';

        if ($content === '') {
            throw new ProviderException(
                'openai',
                'OpenAI returned an empty message content.',
                attempt: $attempt,
                reason: 'invalid_json'
            );
        }

        return new AiResponse(
            content: $content,
            model: $response->model ?? '',
            promptTokens: $response->usage?->promptTokens ?? 0,
            completionTokens: $response->usage?->completionTokens ?? 0,
            latencyMs: $latencyMs,
        );
    }

    private function buildClient(): Client
    {
        return (new Factory())
            ->withApiKey((string) ($this->config['api_key'] ?? ''))
            ->withBaseUri((string) ($this->config['base_url'] ?? 'https://api.openai.com/v1'))
            ->withHttpClient(new GuzzleClient([
                'timeout' => (int) ($this->config['timeout'] ?? 45),
                'connect_timeout' => 10,
            ]))
            ->make();
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     *
     * @return array<string, mixed>
     */
    private function buildPayload(array $messages, array $options): array
    {
        $payload = [
            'model' => (string) ($options['model'] ?? ($this->config['model'] ?? 'gpt-4o-mini')),
            'messages' => $messages,
        ];

        foreach (['temperature', 'max_tokens', 'top_p', 'frequency_penalty', 'presence_penalty'] as $optional) {
            if (array_key_exists($optional, $options) && $options[$optional] !== null) {
                $payload[$optional] = $options[$optional];
            }
        }

        if (isset($options['response_format'])) {
            $payload['response_format'] = $options['response_format'];
        } elseif (isset($options['json_schema'])) {
            // إخراج منظم عبر response_format: json_schema (SRS-AI-M03 — يقصّر دورة التحقق).
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'evaluation_result',
                    'strict' => true,
                    'schema' => $options['json_schema'],
                ],
            ];
        }

        return $payload;
    }

    private function reasonForStatus(int $status): string
    {
        return match (true) {
            $status === 429 => 'rate_limited',
            $status === 408, $status === 425 => 'timeout',
            $status >= 500 => '5xx',
            default => 'api_error',
        };
    }

    private function reasonForTransportException(TransporterException $e): string
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'curl error 28') || str_contains($message, 'timed out') || str_contains($message, 'timeout')
            ? 'timeout'
            : 'network';
    }

    private function providerException(
        Throwable $previous,
        int $attempt,
        string $reason,
        string $message,
    ): ProviderException {
        return new ProviderException(
            'openai',
            $message,
            attempt: $attempt,
            reason: $reason,
            context: ['previous' => $previous::class],
            previous: $previous,
        );
    }
}
