<?php

namespace Tests\Unit\Ai;

use App\Ai\Providers\AiRequestLogger;
use App\Enums\ModelUsed;
use App\Models\AiRequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('writes a calibration row with identifiers and metrics only (FR-207)', function () {
    $logger = new AiRequestLogger();

    $log = $logger->log([
        'evaluation_id' => 42,
        'project_id' => 17,
        'dimension' => 'technical_quality',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'attempt' => 2,
        'success' => true,
        'latency_ms' => 8200,
        'prompt_tokens' => 1450,
        'completion_tokens' => 380,
        'failure_reason' => null,
        'fallback_reason' => null,
        'consensus_round' => false,
    ]);

    expect($log)->toBeInstanceOf(AiRequestLog::class);
    expect($log->evaluation_id)->toBe(42);
    expect($log->project_id)->toBe(17);
    expect($log->dimension)->toBe('technical_quality');
    expect($log->provider)->toBe(ModelUsed::OPENAI);
    expect($log->model)->toBe('gpt-4o-mini');
    expect($log->attempt)->toBe(2);
    expect($log->success)->toBeTrue();
    expect($log->latency_ms)->toBe(8200);
    expect($log->prompt_tokens)->toBe(1450);
    expect($log->completion_tokens)->toBe(380);
    expect($log->consensus_round)->toBeFalse();

    $this->assertDatabaseHas('ai_request_logs', [
        'evaluation_id' => 42,
        'provider' => 'openai',
        'success' => true,
        'latency_ms' => 8200,
    ]);
});

it('never persists sensitive project content (SRS-TEST-AI-11)', function () {
    $logger = new AiRequestLogger();

    $log = $logger->log([
        'evaluation_id' => 7,
        'project_id' => 3,
        'dimension' => 'market_viability',
        'provider' => 'claude',
        'model' => 'claude-3-5-haiku-latest',
        'attempt' => 1,
        'success' => true,
        // حقول محاولة تهريب محتوى — يجب أن تُتجاهل بصمت.
        'description' => 'سرّية: مشروع يربط المستثمرين بشركة ناشئة في الرياض.',
        'full_prompt' => 'SYSTEM: قيّم هذا المشروع...',
        'github_url' => 'https://github.com/secret-owner/secret-repo',
        'user_email' => 'owner@example.com',
        'raw_response' => '{"score": 99, "analysis": "confidential"}',
    ]);

    $attributes = $log->getAttributes();

    expect($attributes)->not->toHaveKey('description');
    expect($attributes)->not->toHaveKey('full_prompt');
    expect($attributes)->not->toHaveKey('github_url');
    expect($attributes)->not->toHaveKey('user_email');
    expect($attributes)->not->toHaveKey('raw_response');

    // الجدول نفسه لا يحتوي أعمدة محتوى.
    $columns = collect($log->getConnection()->getSchemaBuilder()->getColumnListing('ai_request_logs'));
    expect($columns)->not->toContain('description');
    expect($columns)->not->toContain('github_url');
    expect($columns)->not->toContain('user_email');
    expect($columns)->not->toContain('raw_response');
});

it('accepts a ModelUsed enum as the provider value', function () {
    $logger = new AiRequestLogger();

    $log = $logger->log([
        'evaluation_id' => 1,
        'provider' => ModelUsed::CLAUDE,
        'model' => 'claude-3-5-haiku',
        'success' => true,
    ]);

    expect($log->provider)->toBe(ModelUsed::CLAUDE);
});

it('rejects an unknown provider', function () {
    $logger = new AiRequestLogger();

    expect(fn () => $logger->log([
        'provider' => 'gemini',
        'model' => 'gemini-pro',
        'success' => true,
    ]))->toThrow(InvalidArgumentException::class);
});

it('requires the success flag', function () {
    $logger = new AiRequestLogger();

    expect(fn () => $logger->log([
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
    ]))->toThrow(InvalidArgumentException::class);
});
