<?php

namespace Tests\Feature\Evaluation;

use App\Ai\Agents\EvaluationOrchestrator;
use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Unit\Ai\Fakes\AiTestFactory;
use Tests\Unit\Ai\Fakes\FakeAiProvider;

uses(RefreshDatabase::class);

/**
 * محرك ناجح: كل الأبعاد score موحّد.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function retryQueues(float $score = 70.0): array
{
    $queues = [];

    foreach (array_keys(AiTestFactory::DIMENSIONS) as $dimension) {
        $queues[$dimension] = [
            FakeAiProvider::response($score, AiTestFactory::subScores($dimension, $score)),
        ];
    }

    return $queues;
}

/**
 * محرك جزئي: 3 أبعاد تنجح والباقي يفشل → تقرير partial (SRS-AI-F03).
 *
 * @return array<string, list<array<string, mixed>>>
 */
function partialQueues(float $score = 70.0): array
{
    $success = ['technical_quality', 'innovation', 'market_viability'];
    $queues = [];

    foreach ($success as $dimension) {
        $queues[$dimension] = [
            FakeAiProvider::response($score, AiTestFactory::subScores($dimension, $score)),
        ];
    }

    return $queues;
}

function bindRetryEngine(array $queues): void
{
    app()->instance(EvaluationOrchestrator::class, AiTestFactory::orchestrator($queues));
}

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);

    Sanctum::actingAs($this->owner);
});

it('retries a failed evaluation and completes it (SRS-API-46 / US-019-S4)', function () {
    // مرحلة 1: محرك فاشل → تقييم يفشل.
    bindRetryEngine([]);
    $this->postJson("/api/projects/{$this->project->id}/evaluate")->assertStatus(201);

    $evaluation = Evaluation::where('project_id', $this->project->id)->first();
    expect($evaluation->status)->toBe(EvaluationStatus::FAILED);
    expect($evaluation->error_log)->not->toBeNull();

    // مرحلة 2: محرك ناجح → إعادة المحاولة تُكمل التقييم.
    bindRetryEngine(retryQueues());

    $this->postJson("/api/projects/{$this->project->id}/evaluations/{$evaluation->id}/retry")
        ->assertStatus(202)
        ->assertJsonPath('data.id', $evaluation->id)
        ->assertJsonPath('data.retry_count', 1)
        ->assertJsonPath('data.status', 'processing');

    $fresh = $evaluation->fresh();
    expect($fresh->status)->toBe(EvaluationStatus::COMPLETED);
    expect($fresh->retry_count)->toBe(1);
    expect($fresh->overall_score)->toBe(70.0);
});

it('rejects retry of a completed evaluation with NOT_FAILED (US-019)', function () {
    bindRetryEngine(retryQueues());
    $this->postJson("/api/projects/{$this->project->id}/evaluate")->assertStatus(201);

    $evaluation = Evaluation::where('project_id', $this->project->id)->first();
    expect($evaluation->status)->toBe(EvaluationStatus::COMPLETED);

    $this->postJson("/api/projects/{$this->project->id}/evaluations/{$evaluation->id}/retry")
        ->assertStatus(422)
        ->assertJsonPath('code', 'NOT_FAILED');
});

it('returns the cached completed result instead of retrying within 24h (SRS-AI-E05)', function () {
    // تقييم مكتمل حديث (أقل من 24h) → last_evaluation_at محدد.
    bindRetryEngine(retryQueues());
    $this->postJson("/api/projects/{$this->project->id}/evaluate")->assertStatus(201);

    // تقييم فاشل لاحق (سجل مباشر — محفوظ للتدقيق، لا يُحدّث last_evaluation_at).
    $failed = Evaluation::create([
        'project_id' => $this->project->id,
        'version' => 2,
        'status' => EvaluationStatus::FAILED,
        'error_log' => [[
            'type' => 'all_providers_failed',
            'message' => 'simulated failure',
            'timestamp' => now()->toISOString(),
        ]],
    ]);

    $this->postJson("/api/projects/{$this->project->id}/evaluations/{$failed->id}/retry")
        ->assertStatus(200)
        ->assertJsonPath('cached', true)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.overall_score', 70);
});

it('blocks retry of a partial evaluation during its 1h cooldown (data-model.md §2.4)', function () {
    // تقييم جزئي (3/5 أبعاد) → حالة partial + last_evaluation_at.
    bindRetryEngine(partialQueues());
    $this->postJson("/api/projects/{$this->project->id}/evaluate")->assertStatus(201);

    $evaluation = Evaluation::where('project_id', $this->project->id)->first();
    expect($evaluation->status)->toBe(EvaluationStatus::PARTIAL);

    $this->postJson("/api/projects/{$this->project->id}/evaluations/{$evaluation->id}/retry")
        ->assertStatus(429)
        ->assertJsonPath('code', 'COOLDOWN_ACTIVE')
        ->assertHeader('Retry-After');
});

it('retries a partial evaluation after its 1h cooldown expires', function () {
    bindRetryEngine(partialQueues());
    $this->postJson("/api/projects/{$this->project->id}/evaluate")->assertStatus(201);

    $evaluation = Evaluation::where('project_id', $this->project->id)->first();
    expect($evaluation->status)->toBe(EvaluationStatus::PARTIAL);

    // تجاوز مهلة 1h: أعد ضبط last_evaluation_at على الماضي ثم أكمل الأبعاد الناقصة بمحرك ناجح.
    $this->project->forceFill(['last_evaluation_at' => now()->subHours(2)])->saveQuietly();
    bindRetryEngine(retryQueues());

    $this->postJson("/api/projects/{$this->project->id}/evaluations/{$evaluation->id}/retry")
        ->assertStatus(202);

    expect($evaluation->fresh()->status)->toBe(EvaluationStatus::COMPLETED);
});

it('forbids retry by a non-owner (SRS-F05-05)', function () {
    $failed = Evaluation::create([
        'project_id' => $this->project->id,
        'version' => 1,
        'status' => EvaluationStatus::FAILED,
    ]);

    $other = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($other);

    $this->postJson("/api/projects/{$this->project->id}/evaluations/{$failed->id}/retry")
        ->assertStatus(403);
});
