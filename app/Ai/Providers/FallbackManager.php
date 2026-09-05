<?php

namespace App\Ai\Providers;

use App\Exceptions\Ai\AllProvidersFailedException;
use App\Exceptions\Ai\ProviderException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * إدارة التحويل الثنائي الاتجاه بين المزوّدين (plan.md §3.2 — SRS-AI-F01/F02/F03/F04).
 *
 * - ترتيب مزوّدين محدداً من `config('ai.primary_provider')` مع احترام كسّار الدائرة.
 * - عند `ProviderException` (شبكة/5xx/مهلة/JSON/خارج النطاق) يُحوَّل إلى المزوّد البديل.
 * - إعادة المحاولة (3 محاولات بتأخير تصاعدي 0/3/9 ثوانٍ) تعيش **داخل** هذه الطبقة — SRS-AI-F03.
 * - كسّار دائرة عبر Redis (المفتاح `ai:primary_provider`، TTL 5 دقائق): بعد 3 إخفاقات
 *   متتالية للمزوّد الأساسي يُثبَّت البديل — يمنع حرق المحاولات على مزوّد معطّل.
 * - تسجيل سبب التحويل في `ai_request_logs.fallback_reason`.
 * - عند استنفاد كل المحاولات على المزوّدين معاً → `AllProvidersFailedException` (FR-222).
 */
final class FallbackManager
{
    /** مفتاح Redis الذي يُثبّت المزوّد الفعّال (Circuit Breaker). */
    public const CIRCUIT_BREAKER_KEY = 'ai:primary_provider';

    /** عداد الإخفاقات المتتالية للمزوّد الأساسي. */
    public const CONSECUTIVE_FAILURES_KEY = 'ai:primary_consecutive_failures';

    /** مدة تثبيت البديل عند الكسر — 5 دقائق. */
    public const CIRCUIT_BREAKER_TTL_SECONDS = 300;

    /** عدد الإخفاقات المتتالية المطلوبة لكسر الدائرة. */
    public const CONSECUTIVE_FAILURES_THRESHOLD = 3;

    /** التأخير التصاعدي الافتراضي قبل كل محاولة (ثوانٍ) — فوري/3/9. */
    public const DEFAULT_RETRY_DELAYS = [0, 3, 9];

    private readonly array $providers;

    private readonly AiRequestLogger $logger;

    private readonly string $primaryProvider;

    private readonly array $retryDelays;

    private readonly ?\Closure $sleeper;

    /**
     * @param  array<string, AiProviderContract>  $providers  مفاتيح: 'openai' | 'claude'
     * @param  list<int>  $retryDelays  التأخير قبل كل محاولة لكل مزوّد (SRS-AI-F03)
     * @param  callable(int): void|null  $sleeper  حقن النوم لإمكانية الاختبار الحتمي
     */
    public function __construct(
        array $providers,
        AiRequestLogger $logger,
        ?string $primaryProvider = null,
        array $retryDelays = self::DEFAULT_RETRY_DELAYS,
        ?callable $sleeper = null,
    ) {
        $this->providers = $providers;
        $this->logger = $logger;
        $this->primaryProvider = $primaryProvider ?? (string) config('ai.primary_provider', 'openai');
        $this->retryDelays = $retryDelays;
        $this->sleeper = $sleeper !== null ? \Closure::fromCallable($sleeper) : null;

        $this->assertProvidersValid();
    }

    /**
     * تنفيذ طلب مع تحويل ثنائي الاتجاه وإعادة محاولات داخلية.
     *
     * @param  callable(AiProviderContract): AiResponse  $request  ينفّذ الاستدعاء على مزوّد مُمرَّر
     * @param  array<string, mixed>  $context  evaluation_id · project_id · dimension · consensus_round — للسجل فقط
     *
     * @throws AllProvidersFailedException عند استنفاد كل المحاولات على المزوّدين معاً
     */
    public function withFallback(callable $request, array $context = []): AiResponse
    {
        $providers = $this->orderedProviders();
        $failures = [];
        $lastFailureReason = null;

        foreach ($providers as $index => $provider) {
            $providerName = $provider->name();
            $attempt = 0;

            foreach ($this->retryDelays as $delay) {
                $attempt++;
                $this->sleep($delay);
                $start = hrtime(true);

                try {
                    $response = $request($provider);
                } catch (ProviderException $e) {
                    $failures[] = $e;
                    $lastFailureReason = $e->reason() ?? $lastFailureReason;

                    $this->logger->log(array_merge($context, [
                        'provider' => $providerName,
                        'model' => $this->modelFor($provider),
                        'attempt' => $attempt,
                        'success' => false,
                        'latency_ms' => $this->elapsedMs($start),
                        'failure_reason' => $e->reason(),
                        'fallback_reason' => $index > 0 ? $lastFailureReason : null,
                    ]));

                    continue;
                }

                // نجاح — سجّل وأعد.
                $this->logger->log(array_merge($context, [
                    'provider' => $providerName,
                    'model' => $response->model !== '' ? $response->model : $this->modelFor($provider),
                    'attempt' => $attempt,
                    'success' => true,
                    'latency_ms' => $this->elapsedMs($start),
                    'prompt_tokens' => $response->promptTokens,
                    'completion_tokens' => $response->completionTokens,
                    'failure_reason' => null,
                    'fallback_reason' => $index > 0 ? $lastFailureReason : null,
                ]));

                $this->recordProviderSuccess($providerName);

                return $response;
            }

            // استُنفدت محاولات هذا المزوّد.
            $this->recordProviderExhausted($providerName);
        }

        throw new AllProvidersFailedException($failures);
    }

    /**
     * ترتيب المزوّدين الفعّالين: يبدأ بالمثبَّت في كسّار الدائرة إن وُجد،
     * وإلا بالمزوّد الأساسي من `config('ai.primary_provider')`.
     *
     * @return list<AiProviderContract>
     */
    public function orderedProviders(): array
    {
        $pinned = Cache::get(self::CIRCUIT_BREAKER_KEY);

        if (is_string($pinned) && isset($this->providers[$pinned])) {
            return [
                $this->providers[$pinned],
                $this->providers[$this->other($pinned)],
            ];
        }

        return [
            $this->providers[$this->primaryProvider],
            $this->providers[$this->other($this->primaryProvider)],
        ];
    }

    /**
     * عدد المحاولات لكل مزوّد (حجم جدول التأخير التصاعدي).
     */
    public function attemptsPerProvider(): int
    {
        return count($this->retryDelays);
    }

    private function assertProvidersValid(): void
    {
        foreach (['openai', 'claude'] as $expected) {
            if (! isset($this->providers[$expected]) || ! $this->providers[$expected] instanceof AiProviderContract) {
                throw new RuntimeException(sprintf('FallbackManager requires a [%s] provider implementing AiProviderContract.', $expected));
            }
        }

        if ($this->primaryProvider === null) {
            $this->primaryProvider = (string) config('ai.primary_provider', 'openai');
        }

        if (! in_array($this->primaryProvider, ['openai', 'claude'], true)) {
            throw new RuntimeException(sprintf('Invalid primary provider [%s]. Expected openai|claude.', $this->primaryProvider));
        }
    }

    /**
     * عكس المزوّد (2-way fallback).
     */
    private function other(string $provider): string
    {
        return $provider === 'openai' ? 'claude' : 'openai';
    }

    private function modelFor(AiProviderContract $provider): string
    {
        return (string) config("ai.{$provider->name()}.model", '');
    }

    private function elapsedMs(int $start): int
    {
        return (int) ((hrtime(true) - $start) / 1_000_000);
    }

    private function sleep(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);

            return;
        }

        sleep($seconds);
    }

    /**
     * نجاح المزوّد الأساسي يصفّر عداد الإخفاقات المتتالية.
     */
    private function recordProviderSuccess(string $providerName): void
    {
        if ($providerName === $this->primaryProvider) {
            Cache::forget(self::CONSECUTIVE_FAILURES_KEY);
        }
    }

    /**
     * استنفاد محاولات المزوّد الأساسي → زيادة العداد، وعند العتبة يُثبَّت البديل.
     */
    private function recordProviderExhausted(string $providerName): void
    {
        if ($providerName !== $this->primaryProvider) {
            return;
        }

        $count = ((int) Cache::get(self::CONSECUTIVE_FAILURES_KEY, 0)) + 1;
        Cache::put(self::CONSECUTIVE_FAILURES_KEY, $count, self::CIRCUIT_BREAKER_TTL_SECONDS);

        if ($count >= self::CONSECUTIVE_FAILURES_THRESHOLD) {
            Cache::put(
                self::CIRCUIT_BREAKER_KEY,
                $this->other($this->primaryProvider),
                self::CIRCUIT_BREAKER_TTL_SECONDS
            );
            Cache::forget(self::CONSECUTIVE_FAILURES_KEY);
        }
    }
}
