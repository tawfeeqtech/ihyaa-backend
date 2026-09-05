<?php

namespace Tests\Unit\Ai;

use App\Ai\Providers\AiProviderContract;
use App\Ai\Providers\AiRequestLogger;
use App\Ai\Providers\AiResponse;
use App\Ai\Providers\FallbackManager;
use App\Exceptions\Ai\AllProvidersFailedException;
use App\Exceptions\Ai\ProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

/**
 * مزوّد وهمي حتمي لاختبارات FallbackManager (plan.md §9.2 — FakeAiProvider).
 */
final class FallbackFakeProvider implements AiProviderContract
{
    public function __construct(
        private readonly string $name,
        private readonly ?ProviderException $failure = null,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function supportsStructuredOutput(): bool
    {
        return true;
    }

    public function chat(array $messages, array $options): AiResponse
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new AiResponse(
            content: $this->name . '-response',
            model: $this->name . '-model',
            promptTokens: 10,
            completionTokens: 5,
            latencyMs: 2,
        );
    }
}

/**
 * FallbackManager بلا تأخير (0/0/0) ونوم محقون — اختبار حتمي سريع.
 */
function fallbackManager(FallbackFakeProvider $openai, FallbackFakeProvider $claude): FallbackManager
{
    return new FallbackManager(
        providers: ['openai' => $openai, 'claude' => $claude],
        logger: new AiRequestLogger(),
        primaryProvider: 'openai',
        retryDelays: [0, 0, 0],
        sleeper: fn (int $seconds) => null,
    );
}

it('falls back to Claude when OpenAI fails (SRS-AI-F01/F02)', function () {
    $openai = new FallbackFakeProvider('openai', new ProviderException('openai', reason: '5xx', attempt: 1));
    $claude = new FallbackFakeProvider('claude');
    $manager = fallbackManager($openai, $claude);

    $response = $manager->withFallback(
        fn (AiProviderContract $provider) => $provider->chat([], []),
        ['evaluation_id' => 42, 'project_id' => 17, 'dimension' => 'technical_quality']
    );

    expect($response->content)->toBe('claude-response');
    expect($response->model)->toBe('claude-model');

    // 3 محاولات فاشلة لـ OpenAI + محاولة ناجحة واحدة لـ Claude.
    $this->assertDatabaseCount('ai_request_logs', 4);
    $this->assertDatabaseHas('ai_request_logs', [
        'provider' => 'claude',
        'success' => true,
        'fallback_reason' => '5xx',
    ]);
});

it('throws AllProvidersFailedException when both providers are exhausted', function () {
    $openai = new FallbackFakeProvider('openai', new ProviderException('openai', reason: 'timeout', attempt: 1));
    $claude = new FallbackFakeProvider('claude', new ProviderException('claude', reason: '5xx', attempt: 1));
    $manager = fallbackManager($openai, $claude);

    try {
        $manager->withFallback(fn (AiProviderContract $provider) => $provider->chat([], []));
    } catch (AllProvidersFailedException $e) {
        expect($e->failures())->toHaveCount(6); // 3 لـ OpenAI + 3 لـ Claude
        expect($e->failures()[0])->toBeInstanceOf(ProviderException::class);
        $this->assertDatabaseCount('ai_request_logs', 6);

        return;
    }

    $this->fail('Expected AllProvidersFailedException was not thrown.');
});

it('trips the Redis circuit breaker after 3 consecutive primary failures (SRS-AI-F02)', function () {
    $openai = new FallbackFakeProvider('openai', new ProviderException('openai', reason: 'network', attempt: 1));
    $claude = new FallbackFakeProvider('claude');
    $manager = fallbackManager($openai, $claude);

    $call = fn () => $manager->withFallback(fn (AiProviderContract $provider) => $provider->chat([], []));

    $call();
    $call();
    $call();

    // بعد 3 إخفاقات متتالية للمزوّد الأساسي، يُثبَّت البديل لمدة 5 دقائق.
    expect(Cache::get(FallbackManager::CIRCUIT_BREAKER_KEY))->toBe('claude');

    // الترتيب الفعّال يبدأ الآن بالمزوّد البديل.
    $ordered = array_map(
        fn (AiProviderContract $provider) => $provider->name(),
        $manager->orderedProviders()
    );
    expect($ordered)->toBe(['claude', 'openai']);
});

it('resets the consecutive-failure counter when the primary recovers', function () {
    $openai = new FallbackFakeProvider('openai', new ProviderException('openai', reason: 'network', attempt: 1));
    $claude = new FallbackFakeProvider('claude');
    $manager = fallbackManager($openai, $claude);

    $call = fn () => $manager->withFallback(fn (AiProviderContract $provider) => $provider->chat([], []));

    $call();
    $call();

    expect(Cache::get(FallbackManager::CIRCUIT_BREAKER_KEY))->toBeNull();
    expect((int) Cache::get(FallbackManager::CONSECUTIVE_FAILURES_KEY))->toBe(2);

    // المزوّد الأساسي يعود: نجاحه يصفّر العداد.
    $healthyOpenai = new FallbackFakeProvider('openai');
    $healthyManager = fallbackManager($healthyOpenai, $claude);

    $healthyManager->withFallback(fn (AiProviderContract $provider) => $provider->chat([], []));

    expect(Cache::get(FallbackManager::CONSECUTIVE_FAILURES_KEY))->toBeNull();
    expect(Cache::get(FallbackManager::CIRCUIT_BREAKER_KEY))->toBeNull();
});
