<?php

namespace Tests\Feature\Evaluation;

use App\Ai\Agents\EvaluationOrchestrator;
use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use App\Services\Evaluation\EvaluationCacheService;
use App\Services\Evaluation\EvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Unit\Ai\Fakes\AiTestFactory;
use Tests\Unit\Ai\Fakes\FakeAiProvider;

uses(RefreshDatabase::class);

/**
 * تثبيت محرك وهمي ناجح (كل الأبعاد score موحّد).
 */
function bindSuccessEngine(float $score = 70.0): void
{
    $queues = [];

    foreach (array_keys(AiTestFactory::DIMENSIONS) as $dimension) {
        $queues[$dimension] = [
            FakeAiProvider::response($score, AiTestFactory::subScores($dimension, $score)),
        ];
    }

    app()->instance(EvaluationOrchestrator::class, AiTestFactory::orchestrator($queues));
}

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);

    Sanctum::actingAs($this->owner);
});

// ——————————————————————— EvaluationCacheService (plan.md §4.2) ———————————————————————

it('stores and forgets result and cooldown cache keys (plan.md §4.2)', function () {
    $cache = app(EvaluationCacheService::class);

    $cache->storeResult(1, ['overall_score' => 70.0, 'dimensions' => []]);
    expect($cache->cachedResult(1))->toBe(['overall_score' => 70.0, 'dimensions' => []]);
    expect(Cache::has(sprintf(EvaluationCacheService::RESULT_KEY, 1)))->toBeTrue();

    $cache->storeCooldown(1, ['next_evaluation_at' => now()->addHours(24)->toISOString(), 'remaining_seconds' => 86000], 86000);
    expect($cache->cachedCooldown(1))->toHaveKey('next_evaluation_at');

    // النتيجة مفتاحها evaluation-scoped (لا يعرف المشروع) — تُنسى عبر forgetResult.
    $cache->forgetResult(1);
    expect($cache->cachedResult(1))->toBeNull();

    // الهدوء مفتاحه project-scoped — forgetProject تنساه (إبطال المشروع §4.3).
    $cache->forgetProject(1);
    expect($cache->cachedCooldown(1))->toBeNull();
});

it('returns the cached evaluation response while the 24h cooldown is active (SRS-AI-C03)', function () {
    bindSuccessEngine();

    $this->postJson("/api/projects/{$this->project->id}/evaluate")->assertStatus(201);

    $service = app(EvaluationService::class);
    $project = $this->project->fresh();

    $cached = $service->cachedEvaluationResponse($project);

    expect($cached)->not->toBeNull();
    expect($cached['overall_score'])->toBe(70.0);
    expect($cached['status'])->toBe('completed');
    expect($cached)->toHaveKey('next_evaluation_at');
    expect($cached)->toHaveKey('remaining_seconds');

    // كاش الهدوء متاح في Redis/ذاكرة التخزين بعد الاكتمال.
    expect(app(EvaluationCacheService::class)->cachedCooldown((int) $project->id))->not->toBeNull();
});

it('returns cooldown info for the project timer (US-024 / plan.md §4.1)', function () {
    bindSuccessEngine();

    $this->postJson("/api/projects/{$this->project->id}/evaluate")->assertStatus(201);

    $service = app(EvaluationService::class);
    $info = $service->cooldownInfo($this->project->fresh());

    expect($info)->not->toBeNull();
    expect($info['remaining_seconds'])->toBeGreaterThan(0);
    expect($info)->toHaveKey('next_evaluation_at');
});

it('clears the cooldown cache when an evaluation fails (SRS-AI-E05)', function () {
    $cache = app(EvaluationCacheService::class);
    $service = app(EvaluationService::class);

    // كاش هدوء قديم/موجود — يُفترض إبطاله عند الفشل.
    $cache->storeCooldown((int) $this->project->id, [
        'next_evaluation_at' => now()->addHours(24)->toISOString(),
        'remaining_seconds' => 86000,
    ], 86000);
    expect($cache->cachedCooldown((int) $this->project->id))->not->toBeNull();

    // محرك فاشل (كل الأبعاد بلا استجابات) → تقييم فاشل.
    app()->instance(EvaluationOrchestrator::class, AiTestFactory::orchestrator([]));

    $failed = Evaluation::create([
        'project_id' => $this->project->id,
        'version' => 1,
        'status' => EvaluationStatus::PENDING,
    ]);

    $service->runEvaluation($failed);

    expect($failed->fresh()->status)->toBe(EvaluationStatus::FAILED);
    expect($cache->cachedCooldown((int) $this->project->id))->toBeNull();
});

it('forgets the project cooldown key via forgetProject (plan.md §4.3)', function () {
    $cache = app(EvaluationCacheService::class);

    $cache->storeCooldown(5, ['next_evaluation_at' => now()->addHours(24)->toISOString(), 'remaining_seconds' => 86000], 86000);
    expect($cache->cachedCooldown(5))->not->toBeNull();

    $cache->forgetProject(5);
    expect($cache->cachedCooldown(5))->toBeNull();
});
