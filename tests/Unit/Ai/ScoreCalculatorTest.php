<?php

namespace Tests\Unit\Ai;

use App\Support\ScoreCalculator;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

uses(TestCase::class);

it('computes the exact weighted average of the five dimensions', function () {
    $calculator = new ScoreCalculator();

    $score = $calculator->calculate([
        'technical_quality' => 71.2,
        'innovation' => 80.0,
        'market_viability' => 55.0,
        'team_completeness' => 62.0,
        'documentation' => 88.0,
    ]);

    // 71.2×0.25 + 80.0×0.25 + 55.0×0.20 + 62.0×0.15 + 88.0×0.15 = 71.3 (US-015-S2)
    expect($score)->toBe(71.3);
});

it('computes the weighted average of a dimension from its sub-weights', function () {
    $calculator = new ScoreCalculator();

    $score = $calculator->calculateDimension('technical_quality', [
        'code_structure' => 78.0,
        'architecture' => 71.0,
        'testing' => 60.0,
        'ci_cd' => 55.0,
        'documentation' => 82.0,
    ]);

    // 78×0.40 + 71×0.30 + 60×0.15 + 55×0.10 + 82×0.05 = 71.1
    expect($score)->toBe(71.1);
});

it('rounds the overall score to the nearest 0.1', function () {
    Config::set('ai.weights', ['a' => 1.0]);
    $calculator = new ScoreCalculator();

    expect($calculator->calculate(['a' => 71.24]))->toBe(71.2);
    expect($calculator->calculate(['a' => 71.26]))->toBe(71.3);
});

it('throws when dimension weights do not sum to 1.0', function () {
    Config::set('ai.weights', [
        'technical_quality' => 0.6,
        'innovation' => 0.3,
    ]);

    $calculator = new ScoreCalculator();

    expect(fn () => $calculator->calculate(['technical_quality' => 50, 'innovation' => 50]))
        ->toThrow(InvalidArgumentException::class);
});

it('throws when a dimension score is missing', function () {
    $calculator = new ScoreCalculator();

    expect(fn () => $calculator->calculate(['technical_quality' => 70.0]))
        ->toThrow(InvalidArgumentException::class);
});

it('throws when sub-weights of a dimension do not sum to 1.0', function () {
    Config::set('ai.sub_weights.technical_quality', [
        'code_structure' => 0.7,
        'architecture' => 0.2,
    ]);

    $calculator = new ScoreCalculator();

    expect(fn () => $calculator->calculateDimension('technical_quality', [
        'code_structure' => 80.0,
        'architecture' => 60.0,
    ]))->toThrow(InvalidArgumentException::class);
});

it('throws for an unknown dimension', function () {
    $calculator = new ScoreCalculator();

    expect(fn () => $calculator->calculateDimension('unknown_dimension', ['x' => 50]))
        ->toThrow(InvalidArgumentException::class);
});
