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
 * قوائم استجابات ناجحة للأبعاد الخمسة — محرك إعادة التقييم (T074).
 *
 * @return array<string, list<array<string, mixed>>>
 */
function manualReEvaluateQueues(float $score = 70.0): array
{
    $queues = [];

    foreach (array_keys(AiTestFactory::DIMENSIONS) as $dimension) {
        $queues[$dimension] = [
            FakeAiProvider::response($score, AiTestFactory::subScores($dimension, $score)),
        ];
    }

    return $queues;
}

/** تثبيت محرك إعادة التقييم الوهمي في الحاوية. */
function bindManualReEvaluateEngine(array $queues): void
{
    app()->instance(EvaluationOrchestrator::class, AiTestFactory::orchestrator($queues));
}

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);

    Sanctum::actingAs($this->owner);
});

it('rejects re-evaluate without confirm:true (422 CONFIRMATION_REQUIRED)', function () {
    $this->postJson("/api/projects/{$this->project->id}/re-evaluate")
        ->assertStatus(422)
        ->assertJsonPath('code', 'CONFIRMATION_REQUIRED')
        ->assertJsonPath('errors.confirm.0', 'مطلوب true');
});

it('starts a manual evaluation when confirm:true (US-021)', function () {
    bindManualReEvaluateEngine(manualReEvaluateQueues());

    $this->postJson("/api/projects/{$this->project->id}/re-evaluate", ['confirm' => true])
        ->assertStatus(201)
        ->assertJsonPath('data.trigger', 'manual')
        ->assertJsonPath('data.version', 1);

    // queue sync → التنفيذ اكتمل فعلياً في قاعدة البيانات.
    $evaluation = Evaluation::where('project_id', $this->project->id)->first();

    expect($evaluation)->not->toBeNull();
    expect($evaluation->status)->toBe(EvaluationStatus::COMPLETED);
});

it('forbids a non-owner from re-evaluating (SRS-F05-05)', function () {
    $other = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($other);

    $this->postJson("/api/projects/{$this->project->id}/re-evaluate", ['confirm' => true])
        ->assertStatus(403);
});

it('returns 429 COOLDOWN_ACTIVE with Retry-After and next_evaluation_at during the 24h cooldown (US-024)', function () {
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
        ->assertJsonStructure(['last_evaluation_at', 'next_evaluation_at', 'retry_after_seconds']);
});
