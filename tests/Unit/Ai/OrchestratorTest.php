<?php

namespace Tests\Unit\Ai;

use App\Ai\Agents\EvaluationOrchestrator;
use App\Ai\Validation\EvaluationOutputValidator;
use App\Support\ScoreCalculator;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\Ai\Fakes\AiTestFactory;
use Tests\Unit\Ai\Fakes\FakeAiProvider;

uses(TestCase::class);

$dimensions = ['technical_quality', 'innovation', 'market_viability', 'team_completeness', 'documentation'];

function baseInput(array $overrides = []): array
{
    return array_merge([
        'evaluation_id' => 42,
        'project_id' => 17,
        'description' => 'منصة ذكاء اصطناعي لتشخيص الأمراض عن بعد',
        'github_readme' => '# README',
        'business_info' => 'نموذج اشتراك شهري للمستشفيات',
        'team' => [['name' => 'أ', 'role' => 'مطور', 'skills' => ['php']]],
        'model_used' => 'openai',
    ], $overrides);
}

it('aggregates five dimensions into a weighted overall score (SRS-AI-O01 / US-015-S2)', function () use ($dimensions) {
    $queues = [];
    foreach ($dimensions as $d) {
        $queues[$d] = [FakeAiProvider::response(70.0, AiTestFactory::subScores($d, 70.0))];
    }

    $orchestrator = AiTestFactory::orchestrator($queues);
    $report = $orchestrator->evaluate(baseInput());

    expect($report->dimensions)->toHaveCount(5);
    expect($report->overallScore)->toBe(70.0);
    expect($report->partialDimensions)->toBe([]);
    expect($report->modelUsed)->toBe('openai');
    expect($report->confidenceScore)->toBe(90.0);

    // التحقق المستقل عبر ScoreCalculator (US-015-S2)
    $scores = array_map(fn ($r) => $r->score, $report->dimensions);
    expect($report->overallScore)->toBe((new ScoreCalculator())->calculate($scores));
});

it('builds a complete report with gap_analysis, recommendations, required_skills and warnings', function () use ($dimensions) {
    $queues = [];
    foreach ($dimensions as $d) {
        $queues[$d] = [FakeAiProvider::response(70.0, AiTestFactory::subScores($d, 70.0))];
    }

    $report = AiTestFactory::orchestrator($queues)->evaluate(baseInput());
    $array = $report->toArray();

    expect($array)->toHaveKeys(['schema_version', 'overall_score', 'dimensions', 'gap_analysis', 'recommendations', 'required_skills', 'warnings']);
    expect($array['gap_analysis'])->toHaveKeys(['technical_gaps', 'market_gaps', 'team_gaps', 'documentation_gaps']);
    expect($array['recommendations'])->toHaveKeys(['immediate', 'short_term', 'long_term']);
    expect($array['required_skills'])->toBeArray();
    expect($array['warnings'])->toBeArray();
});

it('produces gap analysis entries for low-scoring sub-criteria', function () use ($dimensions) {
    // technical_quality: testing = 30 (فجوة)، البقية 80
    $subScores = AiTestFactory::subScores('technical_quality', 80.0);
    $subScores['testing'] = 30.0;

    $queues = [];
    foreach ($dimensions as $d) {
        if ($d === 'technical_quality') {
            $queues[$d] = [FakeAiProvider::response(70.0, $subScores, weaknesses: ['لا تغطية اختبارات'])];
        } else {
            $queues[$d] = [FakeAiProvider::response(80.0, AiTestFactory::subScores($d, 80.0))];
        }
    }

    $report = AiTestFactory::orchestrator($queues)->evaluate(baseInput());

    expect($report->gapAnalysis['technical_gaps'])->toContain('لا تغطية اختبارات');
    expect(implode(' ', $report->gapAnalysis['technical_gaps']))->toContain('الاختبارات الآلية');
    expect($report->recommendations['immediate'])->not->toBeEmpty();
});

it('builds a successful partial report when only 3 of 5 dimensions complete (SRS-AI-F03)', function () {
    $queues = [
        'technical_quality' => [FakeAiProvider::response(80.0, AiTestFactory::subScores('technical_quality', 80.0))],
        'innovation' => [FakeAiProvider::response(80.0, AiTestFactory::subScores('innovation', 80.0))],
        'market_viability' => 'fail',
        'team_completeness' => 'fail',
        'documentation' => [FakeAiProvider::response(80.0, AiTestFactory::subScores('documentation', 80.0))],
    ];

    $report = AiTestFactory::orchestrator($queues)->evaluate(baseInput());

    expect($report->dimensions)->toHaveCount(3);
    expect($report->partialDimensions)->toBe(['market_viability', 'team_completeness']);
    // (80×0.25 + 80×0.25 + 80×0.15) / 0.65 = 80.0
    expect($report->overallScore)->toBe(80.0);
    // الثقة مخفَّضة 0.8 للتقييم الجزئي
    expect($report->confidenceScore)->toBe(72.0);
    expect(implode(' ', $report->warnings))->toContain('market_viability');
    expect(implode(' ', $report->warnings))->toContain('team_completeness');
});

it('throws when fewer than 3 dimensions complete', function () {
    $queues = [
        'technical_quality' => [FakeAiProvider::response(80.0, AiTestFactory::subScores('technical_quality', 80.0))],
        'innovation' => [FakeAiProvider::response(80.0, AiTestFactory::subScores('innovation', 80.0))],
        'market_viability' => 'fail',
        'team_completeness' => 'fail',
        'documentation' => 'fail',
    ];

    expect(fn () => AiTestFactory::orchestrator($queues)->evaluate(baseInput()))
        ->toThrow(RuntimeException::class);
});

it('triggers a single consensus round for a deviant dimension (SRS-AI-O03)', function () use ($dimensions) {
    $providers = [];
    foreach ($dimensions as $d) {
        $providers[$d] = new FakeAiProvider([FakeAiProvider::response(80.0, AiTestFactory::subScores($d, 80.0))]);
    }
    // documentation منحرف بشدة (20 مقابل متوسط البقية 80) → جولة إجماع واحدة تعيد التقييم إلى 75.
    $providers['documentation'] = new FakeAiProvider([
        FakeAiProvider::response(20.0, AiTestFactory::subScores('documentation', 20.0)),
        FakeAiProvider::response(75.0, AiTestFactory::subScores('documentation', 75.0)),
    ]);

    $orchestrator = AiTestFactory::orchestratorWithProviders($providers);
    $report = $orchestrator->evaluate(baseInput());

    // النتيجة المعتمدة = نتيجة جولة الإجماع (75) وليست الأصلية (20)
    expect($report->dimensions['documentation']->score)->toBe(75.0);
    expect($providers['documentation']->calls)->toBe(2);
    expect($providers['technical_quality']->calls)->toBe(1);

    // طلب الإجماع مرَّر سياق مراجعة
    $secondCall = $providers['documentation']->receivedMessages[1];
    expect($secondCall[1]['content'])->toContain('جولة إجماع');

    // overall بعد الإجماع: 80×0.25 + 80×0.25 + 80×0.20 + 80×0.15 + 75×0.15 = 79.3
    expect($report->overallScore)->toBe(79.3);
});

it('does not trigger consensus when all dimensions are within threshold', function () use ($dimensions) {
    $providers = [];
    foreach ($dimensions as $d) {
        $providers[$d] = new FakeAiProvider([FakeAiProvider::response(70.0, AiTestFactory::subScores($d, 70.0))]);
    }

    $orchestrator = AiTestFactory::orchestratorWithProviders($providers);
    $report = $orchestrator->evaluate(baseInput());

    expect($report->dimensions['documentation']->score)->toBe(70.0);
    foreach ($providers as $provider) {
        expect($provider->calls)->toBe(1);
    }
});

it('validates the assembled report via EvaluationOutputValidator before returning', function () use ($dimensions) {
    $queues = [];
    foreach ($dimensions as $d) {
        $queues[$d] = [FakeAiProvider::response(70.0, AiTestFactory::subScores($d, 70.0))];
    }

    $report = AiTestFactory::orchestrator($queues)->evaluate(baseInput());

    $result = (new EvaluationOutputValidator())->validate($report->toArray());
    expect($result['valid'])->toBeTrue();
});
