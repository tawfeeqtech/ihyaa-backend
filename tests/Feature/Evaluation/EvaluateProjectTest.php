<?php

namespace Tests\Feature\Evaluation;

use App\Ai\Agents\EvaluationOrchestrator;
use App\Enums\EvaluationStatus;
use App\Enums\ProjectStatus;
use App\Enums\VisibilityLevel;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Unit\Ai\Fakes\AiTestFactory;
use Tests\Unit\Ai\Fakes\FakeAiProvider;

uses(RefreshDatabase::class);

/**
 * قوائم استجابات ناجحة للأبعاد الخمسة — كل بُعد يرد بـ score موحّد.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function evaluationQueues(float $score = 70.0): array
{
    $queues = [];

    foreach (array_keys(AiTestFactory::DIMENSIONS) as $dimension) {
        $queues[$dimension] = [
            FakeAiProvider::response($score, AiTestFactory::subScores($dimension, $score)),
        ];
    }

    return $queues;
}

/** تثبيت محرك تقييم وهمي في الحاوية (EvaluationEngineFactory::make() يقرأه أولاً). */
function bindEvaluationEngine(array $queues): void
{
    app()->instance(EvaluationOrchestrator::class, AiTestFactory::orchestrator($queues));
}

beforeEach(function () {
    // Scout NullEngine: يمنع أي اتصال بـ Meilisearch أثناء الاختبارات (searchable() = no-op).
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);

    Sanctum::actingAs($this->owner);
});

// ——————————————————————— POST /evaluate ———————————————————————

it('accepts an evaluation request and returns 201 pending (SRS-API-44)', function () {
    bindEvaluationEngine(evaluationQueues());

    $this->postJson("/api/projects/{$this->project->id}/evaluate")
        ->assertStatus(201)
        ->assertJsonStructure([
            'data' => ['id', 'project_id', 'version', 'status', 'trigger', 'message', 'queued_at'],
        ])
        ->assertJsonPath('data.project_id', $this->project->id)
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.trigger', 'auto');

    // queue sync → التنفيذ اكتمل فعلياً في قاعدة البيانات قبل إرجاع الاستجابة.
    $evaluation = Evaluation::where('project_id', $this->project->id)->first();

    expect($evaluation)->not->toBeNull();
    expect($evaluation->status)->toBe(EvaluationStatus::COMPLETED);
    expect($evaluation->overall_score)->toBe(70.0);
    expect($this->project->fresh()->ai_score)->toBe(70.0);
    expect($this->project->fresh()->last_evaluation_at)->not->toBeNull();
});

it('returns 200 cached when a completed evaluation is within the 24h cooldown (SRS-AI-C01/C03)', function () {
    bindEvaluationEngine(evaluationQueues());

    $this->postJson("/api/projects/{$this->project->id}/evaluate")->assertStatus(201);

    $this->postJson("/api/projects/{$this->project->id}/evaluate")
        ->assertStatus(200)
        ->assertJsonPath('cached', true)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.overall_score', 70)
        ->assertJsonStructure(['data' => ['evaluation_id', 'last_evaluation_at', 'next_evaluation_at', 'message']]);
});

it('returns 409 EVALUATION_IN_PROGRESS when an active evaluation exists (US-024-S4)', function () {
    Evaluation::create([
        'project_id' => $this->project->id,
        'version' => 1,
        'status' => EvaluationStatus::PROCESSING,
    ]);

    $this->postJson("/api/projects/{$this->project->id}/evaluate")
        ->assertStatus(409)
        ->assertJsonPath('code', 'EVALUATION_IN_PROGRESS')
        ->assertJsonPath('active_evaluation_id', Evaluation::where('project_id', $this->project->id)->value('id'));
});

it('rejects draft projects as unevaluable (FR-223)', function () {
    $this->project->forceFill(['publication_status' => ProjectStatus::DRAFT])->save();

    $this->postJson("/api/projects/{$this->project->id}/evaluate")
        ->assertStatus(422)
        ->assertJsonPath('code', 'UNEVALUABLE_PROJECT');
});

it('forbids a non-owner from evaluating a project (SRS-F05-05)', function () {
    $other = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($other);

    $this->postJson("/api/projects/{$this->project->id}/evaluate")->assertStatus(403);
});

it('forbids an investor from evaluating a project', function () {
    $investor = User::factory()->investor()->create();
    Sanctum::actingAs($investor);

    $this->postJson("/api/projects/{$this->project->id}/evaluate")->assertStatus(403);
});

// ——————————————————————— POST /re-evaluate ———————————————————————

it('rejects re-evaluate without confirmation (US-021-S3)', function () {
    $this->postJson("/api/projects/{$this->project->id}/re-evaluate")
        ->assertStatus(422)
        ->assertJsonPath('code', 'CONFIRMATION_REQUIRED')
        ->assertJsonPath('errors.confirm.0', 'مطلوب true');
});

it('accepts re-evaluate with confirmation (US-021)', function () {
    bindEvaluationEngine(evaluationQueues());

    $this->postJson("/api/projects/{$this->project->id}/re-evaluate", ['confirm' => true])
        ->assertStatus(201)
        ->assertJsonPath('data.trigger', 'manual')
        ->assertJsonPath('data.version', 1);
});

it('returns 429 COOLDOWN_ACTIVE on manual re-evaluate during the 24h cooldown (US-024)', function () {
    bindEvaluationEngine(evaluationQueues());

    $this->postJson("/api/projects/{$this->project->id}/evaluate")->assertStatus(201);

    $this->postJson("/api/projects/{$this->project->id}/re-evaluate", ['confirm' => true])
        ->assertStatus(429)
        ->assertJsonPath('code', 'COOLDOWN_ACTIVE')
        ->assertHeader('Retry-After')
        ->assertJsonStructure(['last_evaluation_at', 'next_evaluation_at', 'retry_after_seconds']);
});

// ——————————————————————— GET /evaluation-status ———————————————————————

it('reports never_evaluated when no evaluation exists (SRS-API-47)', function () {
    $this->getJson("/api/projects/{$this->project->id}/evaluation-status")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'never_evaluated');
});

it('reports completed progress after an evaluation finishes (US-016-S5)', function () {
    bindEvaluationEngine(evaluationQueues());

    $this->postJson("/api/projects/{$this->project->id}/evaluate")->assertStatus(201);

    $this->getJson("/api/projects/{$this->project->id}/evaluation-status")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.progress.completed_dimensions', 5)
        ->assertJsonPath('data.progress.total_dimensions', 5)
        ->assertJsonPath('data.overall_score', 70)
        ->assertJsonPath('data.can_retry', false)
        ->assertJsonPath('data.notification.type', 'evaluation_completed');
});

// ——————————————————————— GET /evaluations (history) ———————————————————————

it('lists completed evaluations in the history log (SRS-API-19 / US-018)', function () {
    bindEvaluationEngine(evaluationQueues());

    $this->postJson("/api/projects/{$this->project->id}/evaluate")->assertStatus(201);

    $this->getJson("/api/projects/{$this->project->id}/evaluations")
        ->assertStatus(200)
        ->assertJsonStructure(['data' => ['evaluations', 'meta']])
        ->assertJsonPath('data.evaluations.0.overall_score', 70)
        ->assertJsonPath('data.evaluations.0.dimensions.technical_quality', 70)
        ->assertJsonPath('data.meta.shown_count', 1)
        ->assertJsonPath('data.meta.total_completed', 1)
        ->assertJsonPath('data.meta.failed_count', 0);
});

it('exposes only overall scores to non-owners at VISITOR visibility (US-029)', function () {
    $this->project->forceFill(['visibility_level' => VisibilityLevel::VISITOR])->save();

    $investor = User::factory()->investor()->create();
    Sanctum::actingAs($investor);

    $this->getJson("/api/projects/{$this->project->id}/evaluations")
        ->assertStatus(200)
        ->assertJsonStructure(['data' => ['evaluations', 'meta']])
        ->assertJsonMissingPath('data.evaluations.0.dimensions')
        ->assertJsonMissingPath('data.meta.failed_count');
});

it('gives non-owners dimensions in the history log at AFTER_AGREEMENT visibility (T127 / US-038)', function () {
    Evaluation::create([
        'project_id' => $this->project->id,
        'version' => 1,
        'status' => EvaluationStatus::COMPLETED,
        'overall_score' => 70,
        'result' => ['dimensions' => ['technical_quality' => ['score' => 70]]],
    ]);

    $investor = User::factory()->investor()->create();
    Sanctum::actingAs($investor);

    $this->getJson("/api/projects/{$this->project->id}/evaluations")
        ->assertStatus(200)
        ->assertJsonStructure(['data' => ['evaluations', 'meta']])
        ->assertJsonPath('data.evaluations.0.overall_score', 70)
        ->assertJsonPath('data.evaluations.0.dimensions.technical_quality', 70);
});

it('includes comparison data when requested by the owner (?include=comparison — US-023)', function () {
    bindEvaluationEngine(evaluationQueues());

    $this->postJson("/api/projects/{$this->project->id}/evaluate")->assertStatus(201);

    $this->getJson("/api/projects/{$this->project->id}/evaluations?include=comparison")
        ->assertStatus(200)
        ->assertJsonPath('data.comparison.0.version', 1)
        ->assertJsonPath('data.comparison.0.dimensions.technical_quality', 70);
});
