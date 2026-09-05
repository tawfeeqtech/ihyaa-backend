<?php

namespace Tests\Feature\Evaluation;

use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * نتيجة تقييم مكتملة (كل الأبعاد الخمسة بنفس الدرجة) — لبناء سجل مباشر بلا محرك (T060).
 */
function evaluationHistoryResult(float $score = 70.0): array
{
    return [
        'dimensions' => [
            'technical_quality' => ['score' => $score],
            'innovation' => ['score' => $score],
            'market_viability' => ['score' => $score],
            'team_completeness' => ['score' => $score],
            'documentation' => ['score' => $score],
        ],
    ];
}

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);

    Sanctum::actingAs($this->owner);
});

it('lists the last 5 completed evaluations newest-first and counts failures for the owner', function () {
    foreach ([1, 2, 3, 4, 5] as $version) {
        Evaluation::create([
            'project_id' => $this->project->id,
            'version' => $version,
            'status' => EvaluationStatus::COMPLETED,
            'overall_score' => $version * 10,
            'result' => evaluationHistoryResult($version * 10),
            'completed_at' => now()->subMinutes(5 - $version),
        ]);
    }

    // فشلان أحدث — لا يظهران في القائمة، يظهران فقط في failed_count (US-018/019).
    Evaluation::create([
        'project_id' => $this->project->id,
        'version' => 6,
        'status' => EvaluationStatus::FAILED,
        'completed_at' => now()->subMinute(),
    ]);
    Evaluation::create([
        'project_id' => $this->project->id,
        'version' => 7,
        'status' => EvaluationStatus::FAILED,
        'completed_at' => now(),
    ]);

    $this->getJson("/api/projects/{$this->project->id}/evaluations")
        ->assertStatus(200)
        ->assertJsonStructure(['data' => ['evaluations', 'meta']])
        ->assertJsonCount(5, 'data.evaluations')
        ->assertJsonPath('data.evaluations.0.overall_score', 50)
        ->assertJsonPath('data.evaluations.0.dimensions.technical_quality', 50)
        ->assertJsonPath('data.evaluations.4.overall_score', 10)
        ->assertJsonPath('data.meta.shown_count', 5)
        ->assertJsonPath('data.meta.total_completed', 5)
        ->assertJsonPath('data.meta.latest_version', 7)
        ->assertJsonPath('data.meta.failed_count', 2);
});

it('hides failed_count from non-owners (US-029 disclosure)', function () {
    Evaluation::create([
        'project_id' => $this->project->id,
        'version' => 1,
        'status' => EvaluationStatus::COMPLETED,
        'overall_score' => 70,
        'result' => evaluationHistoryResult(),
        'completed_at' => now()->subMinute(),
    ]);
    Evaluation::create([
        'project_id' => $this->project->id,
        'version' => 2,
        'status' => EvaluationStatus::FAILED,
        'completed_at' => now(),
    ]);

    $investor = User::factory()->investor()->create();
    Sanctum::actingAs($investor);

    $this->getJson("/api/projects/{$this->project->id}/evaluations")
        ->assertStatus(200)
        ->assertJsonStructure(['data' => ['evaluations', 'meta']])
        ->assertJsonMissingPath('data.meta.failed_count');
});

it('keeps failed evaluations for retry (no auto-delete)', function () {
    Evaluation::create([
        'project_id' => $this->project->id,
        'version' => 1,
        'status' => EvaluationStatus::FAILED,
        'completed_at' => now()->subMinute(),
    ]);
    Evaluation::create([
        'project_id' => $this->project->id,
        'version' => 2,
        'status' => EvaluationStatus::FAILED,
        'completed_at' => now(),
    ]);

    $this->getJson("/api/projects/{$this->project->id}/evaluations")
        ->assertStatus(200)
        ->assertJsonPath('data.meta.shown_count', 0)
        ->assertJsonPath('data.meta.failed_count', 2);

    // سجل الفشل محفوظ — لم يُحذف تلقائياً (زر إعادة المحاولة متاح — US-019-S4).
    expect(Evaluation::where('project_id', $this->project->id)->count())->toBe(2);
    expect(Evaluation::where('status', EvaluationStatus::FAILED)->count())->toBe(2);
});
