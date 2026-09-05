<?php

namespace Tests\Feature\Evaluation;

use App\Ai\Agents\EvaluationOrchestrator;
use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Unit\Ai\Fakes\AiTestFactory;
use Tests\Unit\Ai\Fakes\FakeAiProvider;

uses(RefreshDatabase::class);

/**
 * T086 — قاعدة الهدوء 24 ساعة (US-024 · SRS-AI-C01) والقفل الذري ضد التزامن.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function cooldownReEvaluateQueues(float $score = 70.0): array
{
    $queues = [];

    foreach (array_keys(AiTestFactory::DIMENSIONS) as $dimension) {
        $queues[$dimension] = [
            FakeAiProvider::response($score, AiTestFactory::subScores($dimension, $score)),
        ];
    }

    return $queues;
}

function bindCooldownReEvaluateEngine(array $queues): void
{
    app()->instance(EvaluationOrchestrator::class, AiTestFactory::orchestrator($queues));
}

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);

    Sanctum::actingAs($this->owner);
});

it('returns 429 COOLDOWN_ACTIVE with Retry-After and next_evaluation_at when re-evaluating within 24h', function () {
    Evaluation::create([
        'project_id' => $this->project->id,
        'version' => 1,
        'status' => EvaluationStatus::COMPLETED,
        'overall_score' => 70,
        'completed_at' => now()->subHour(),
    ]);
    $this->project->forceFill(['last_evaluation_at' => now()->subHour()])->saveQuietly();

    $this->postJson("/api/projects/{$this->project->id}/re-evaluate", ['confirm' => true])
        ->assertStatus(429)
        ->assertJsonPath('code', 'COOLDOWN_ACTIVE')
        ->assertHeader('Retry-After')
        ->assertJsonStructure(['last_evaluation_at', 'next_evaluation_at', 'retry_after_seconds'])
        ->assertJsonPath('retry_after_seconds', fn (int $seconds) => $seconds > 80000);
});

it('allows re-evaluation after the 24h cooldown expires', function () {
    bindCooldownReEvaluateEngine(cooldownReEvaluateQueues());

    Evaluation::create([
        'project_id' => $this->project->id,
        'version' => 1,
        'status' => EvaluationStatus::COMPLETED,
        'overall_score' => 70,
        'completed_at' => now()->subHours(25),
    ]);
    $this->project->forceFill(['last_evaluation_at' => now()->subHours(25)])->saveQuietly();

    $this->postJson("/api/projects/{$this->project->id}/re-evaluate", ['confirm' => true])
        ->assertStatus(201)
        ->assertJsonPath('data.trigger', 'manual')
        ->assertJsonPath('data.version', 2);
});

it('rejects a concurrent second evaluation with EVALUATION_IN_PROGRESS (atomic lock — US-024-S4)', function () {
    Queue::fake(); // يُبقي التقييم الأول pending (لم يُنفَّذ بعد) — كلا الطلبين "في الجو".

    $this->postJson("/api/projects/{$this->project->id}/evaluate")
        ->assertStatus(201);

    $this->postJson("/api/projects/{$this->project->id}/evaluate")
        ->assertStatus(409)
        ->assertJsonPath('code', 'EVALUATION_IN_PROGRESS');

    // وُجد تقييم واحد فقط (pending) — لم يتكرر.
    expect(Evaluation::where('project_id', $this->project->id)->count())->toBe(1);
});
