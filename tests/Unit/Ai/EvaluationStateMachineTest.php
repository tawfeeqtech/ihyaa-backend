<?php

namespace Tests\Unit\Ai;

use App\Enums\EvaluationStatus;
use App\Exceptions\Ai\EvaluationCooldownException;
use App\Exceptions\Ai\EvaluationInProgressException;
use App\Exceptions\Ai\EvaluationNotFailedException;
use App\Support\EvaluationStateMachine;
use DateTimeImmutable;
use InvalidArgumentException;
use Tests\TestCase;

uses(TestCase::class);

// ---- صياغة —-----------------------------------------------------------------

it('allows pending → processing', function () {
    $state = new EvaluationStateMachine();

    expect($state->transition(EvaluationStatus::PENDING, EvaluationStatus::PROCESSING))
        ->toBe(EvaluationStatus::PROCESSING);
});

it('allows processing → completed / partial / failed', function () {
    $state = new EvaluationStateMachine();

    expect($state->transition(EvaluationStatus::PROCESSING, EvaluationStatus::COMPLETED))
        ->toBe(EvaluationStatus::COMPLETED);
    expect($state->transition(EvaluationStatus::PROCESSING, EvaluationStatus::PARTIAL))
        ->toBe(EvaluationStatus::PARTIAL);
    expect($state->transition(EvaluationStatus::PROCESSING, EvaluationStatus::FAILED))
        ->toBe(EvaluationStatus::FAILED);
});

it('allows failed → processing via retry', function () {
    $state = new EvaluationStateMachine();

    expect($state->retry(EvaluationStatus::FAILED))
        ->toBe(EvaluationStatus::PROCESSING);
});

it('allows partial → processing via retry after the 1h cooldown', function () {
    $state = new EvaluationStateMachine();
    $now = new DateTimeImmutable('2026-08-17 12:00:00 UTC');

    $result = $state->retry(EvaluationStatus::PARTIAL, [
        'now' => $now,
        'last_evaluation_at' => $now->modify('-2 hours'),
    ]);

    expect($result)->toBe(EvaluationStatus::PROCESSING);
});

it('allows completed → processing via re-evaluate after 24h', function () {
    $state = new EvaluationStateMachine();
    $now = new DateTimeImmutable('2026-08-17 12:00:00 UTC');

    $result = $state->transition(EvaluationStatus::COMPLETED, EvaluationStatus::PROCESSING, [
        'now' => $now,
        'last_evaluation_at' => $now->modify('-25 hours'),
    ]);

    expect($result)->toBe(EvaluationStatus::PROCESSING);
});

it('allows completed → processing when there is no previous evaluation', function () {
    $state = new EvaluationStateMachine();

    expect($state->transition(EvaluationStatus::COMPLETED, EvaluationStatus::PROCESSING))
        ->toBe(EvaluationStatus::PROCESSING);
});

// ---- انتقالات غير معرّفة —----------------------------------------------------

it('rejects transitions not defined in the graph', function () {
    $state = new EvaluationStateMachine();

    expect(fn () => $state->transition(EvaluationStatus::PENDING, EvaluationStatus::COMPLETED))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $state->transition(EvaluationStatus::COMPLETED, EvaluationStatus::FAILED))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $state->transition(EvaluationStatus::PROCESSING, EvaluationStatus::PROCESSING))
        ->toThrow(InvalidArgumentException::class);
});

it('reports canTransition accurately', function () {
    $state = new EvaluationStateMachine();

    expect($state->canTransition(EvaluationStatus::PENDING, EvaluationStatus::PROCESSING))->toBeTrue();
    expect($state->canTransition(EvaluationStatus::PROCESSING, EvaluationStatus::COMPLETED))->toBeTrue();
    expect($state->canTransition(EvaluationStatus::PENDING, EvaluationStatus::COMPLETED))->toBeFalse();
    expect($state->canTransition(EvaluationStatus::COMPLETED, EvaluationStatus::FAILED))->toBeFalse();
});

// ---- حراس التزامن —------------------------------------------------------------

it('blocks pending → processing when an evaluation is already processing', function () {
    $state = new EvaluationStateMachine();

    expect(fn () => $state->transition(EvaluationStatus::PENDING, EvaluationStatus::PROCESSING, [
        'has_active_processing' => true,
        'project_id' => 17,
    ]))->toThrow(EvaluationInProgressException::class);
});

it('blocks failed → processing when an evaluation is already processing', function () {
    $state = new EvaluationStateMachine();

    expect(fn () => $state->retry(EvaluationStatus::FAILED, [
        'has_active_processing' => true,
    ]))->toThrow(EvaluationInProgressException::class);
});

// ---- حراس فترة الهدوء —---------------------------------------------------------

it('blocks completed → processing before 24h elapse', function () {
    $state = new EvaluationStateMachine();
    $now = new DateTimeImmutable('2026-08-17 12:00:00 UTC');

    expect(fn () => $state->transition(EvaluationStatus::COMPLETED, EvaluationStatus::PROCESSING, [
        'now' => $now,
        'last_evaluation_at' => $now->modify('-1 hour'),
    ]))->toThrow(EvaluationCooldownException::class);
});

it('blocks partial → processing before the 1h cooldown elapses', function () {
    $state = new EvaluationStateMachine();
    $now = new DateTimeImmutable('2026-08-17 12:00:00 UTC');

    expect(fn () => $state->retry(EvaluationStatus::PARTIAL, [
        'now' => $now,
        'last_evaluation_at' => $now->modify('-30 minutes'),
    ]))->toThrow(EvaluationCooldownException::class);
});

// ---- مسار retry —---------------------------------------------------------------

it('rejects retry on a non-failed evaluation', function () {
    $state = new EvaluationStateMachine();

    expect(fn () => $state->retry(EvaluationStatus::COMPLETED))
        ->toThrow(EvaluationNotFailedException::class);
    expect(fn () => $state->retry(EvaluationStatus::PENDING))
        ->toThrow(EvaluationNotFailedException::class);
    expect(fn () => $state->retry(EvaluationStatus::PROCESSING))
        ->toThrow(EvaluationNotFailedException::class);
});
