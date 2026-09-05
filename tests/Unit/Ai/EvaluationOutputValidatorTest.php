<?php

namespace Tests\Unit\Ai;

use App\Ai\Validation\EvaluationOutputValidator;
use App\Ai\Validation\OutputSchema;
use InvalidArgumentException;
use Tests\TestCase;
use Tests\Unit\Ai\Fakes\AiTestFactory;

uses(TestCase::class);

function validReport(): array
{
    $dimensions = [];
    foreach (AiTestFactory::DIMENSIONS as $dimension => $_class) {
        $subScores = AiTestFactory::subScores($dimension, 75.0);
        $dimensions[$dimension] = [
            'score' => round(array_sum($subScores) / count($subScores), 1),
            'sub_scores' => $subScores,
            'strengths' => ['قوة'],
            'weaknesses' => [],
        ];
    }

    return [
        'schema_version' => OutputSchema::SCHEMA_VERSION,
        'overall_score' => 75.0,
        'dimensions' => $dimensions,
        'gap_analysis' => [
            'technical_gaps' => [],
            'market_gaps' => [],
            'team_gaps' => [],
            'documentation_gaps' => [],
        ],
        'recommendations' => [
            'immediate' => [],
            'short_term' => [],
            'long_term' => [],
        ],
        'required_skills' => ['مهارة'],
        'warnings' => [],
        'confidence_score' => 80.0,
    ];
}

it('passes a fully valid report (SRS-TEST-AI-10)', function () {
    $result = (new EvaluationOutputValidator())->validate(validReport());

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBe([]);
});

it('fails when a top-level required key is missing', function (string $key) {
    $report = validReport();
    unset($report[$key]);

    $result = (new EvaluationOutputValidator())->validate($report);

    expect($result['valid'])->toBeFalse();
    expect(implode(' ', $result['errors']))->toContain($key);
})->with(['schema_version', 'overall_score', 'dimensions', 'gap_analysis', 'recommendations', 'required_skills', 'warnings']);

it('rejects an unsupported schema_version', function () {
    $report = validReport();
    $report['schema_version'] = '9.9';

    $result = (new EvaluationOutputValidator())->validate($report);

    expect($result['valid'])->toBeFalse();
    expect(implode(' ', $result['errors']))->toContain('schema_version');
});

it('rejects an out-of-range overall_score', function () {
    $report = validReport();
    $report['overall_score'] = 100.1;

    $result = (new EvaluationOutputValidator())->validate($report);

    expect($result['valid'])->toBeFalse();
    expect(implode(' ', $result['errors']))->toContain('overall_score');
});

it('rejects a non-numeric overall_score', function () {
    $report = validReport();
    $report['overall_score'] = 'high';

    $result = (new EvaluationOutputValidator())->validate($report);

    expect($result['valid'])->toBeFalse();
});

it('rejects a dimension missing a required key', function () {
    $report = validReport();
    unset($report['dimensions']['technical_quality']['strengths']);

    $result = (new EvaluationOutputValidator())->validate($report);

    expect($result['valid'])->toBeFalse();
    expect(implode(' ', $result['errors']))->toContain('technical_quality');
});

it('rejects a sub_score out of range (0-100)', function () {
    $report = validReport();
    $report['dimensions']['innovation']['sub_scores']['novelty'] = 105.0;

    $result = (new EvaluationOutputValidator())->validate($report);

    expect($result['valid'])->toBeFalse();
    expect(implode(' ', $result['errors']))->toContain('novelty');
});

it('rejects a dimension missing a configured sub-criterion', function () {
    $report = validReport();
    unset($report['dimensions']['market_viability']['sub_scores']['competitive_awareness']);

    $result = (new EvaluationOutputValidator())->validate($report);

    expect($result['valid'])->toBeFalse();
    expect(implode(' ', $result['errors']))->toContain('competitive_awareness');
});

it('rejects a sub-criterion unknown to the configured schema', function () {
    $report = validReport();
    $report['dimensions']['team_completeness']['sub_scores']['hiring_budget'] = 50.0;

    $result = (new EvaluationOutputValidator())->validate($report);

    expect($result['valid'])->toBeFalse();
    expect(implode(' ', $result['errors']))->toContain('hiring_budget');
});

it('rejects a gap_analysis missing a required bucket', function () {
    $report = validReport();
    unset($report['gap_analysis']['market_gaps']);

    $result = (new EvaluationOutputValidator())->validate($report);

    expect($result['valid'])->toBeFalse();
    expect(implode(' ', $result['errors']))->toContain('market_gaps');
});

it('rejects a recommendation horizon that is not a list of strings', function () {
    $report = validReport();
    $report['recommendations']['immediate'] = 'not-an-array';

    $result = (new EvaluationOutputValidator())->validate($report);

    expect($result['valid'])->toBeFalse();
});

it('rejects required_skills containing a non-string', function () {
    $report = validReport();
    $report['required_skills'] = ['مهارة', 42];

    $result = (new EvaluationOutputValidator())->validate($report);

    expect($result['valid'])->toBeFalse();
});

it('rejects an out-of-range top-level confidence_score', function () {
    $report = validReport();
    $report['confidence_score'] = 150.0;

    $result = (new EvaluationOutputValidator())->validate($report);

    expect($result['valid'])->toBeFalse();
    expect(implode(' ', $result['errors']))->toContain('confidence_score');
});

it('rejects partial_dimensions containing an unknown dimension', function () {
    $report = validReport();
    $report['partial_dimensions'] = ['not_a_dimension'];

    $result = (new EvaluationOutputValidator())->validate($report);

    expect($result['valid'])->toBeFalse();
    expect(implode(' ', $result['errors']))->toContain('not_a_dimension');
});

it('assertValid throws InvalidArgumentException for invalid reports and passes for valid ones', function () {
    $validator = new EvaluationOutputValidator();

    expect(fn () => $validator->assertValid(['schema_version' => '1.0']))->toThrow(InvalidArgumentException::class);

    $validator->assertValid(validReport()); // لا يرمي
    expect(true)->toBeTrue();
});
