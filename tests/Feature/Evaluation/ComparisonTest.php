<?php

namespace Tests\Feature\Evaluation;

use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Unit\Ai\Fakes\AiTestFactory;

uses(RefreshDatabase::class);

/**
 * T082 — مصفوفة المقارنة عبر الإصدارات (?include=comparison — US-018/023 · FR-229).
 * آخر 5 مكتملة × درجات الأبعاد الخمسة — والأقدم يُسقط عند السادسة.
 */

/**
 * نتيجة تقييم بدرجة لكل بُعد (مفاتيح الأبعاد الخمسة الفعلية للمحرك).
 *
 * @param  array<string, float>  $scores
 */
function comparisonResultFor(array $scores): array
{
    $dimensions = [];

    foreach ($scores as $dimension => $score) {
        $dimensions[$dimension] = ['score' => $score];
    }

    return ['dimensions' => $dimensions];
}

/** خريطة درجة موحّدة لكل الأبعاد الخمسة. */
function comparisonScoresFor(float $score): array
{
    $scores = [];

    foreach (array_keys(AiTestFactory::DIMENSIONS) as $dimension) {
        $scores[$dimension] = $score;
    }

    return $scores;
}

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);

    Sanctum::actingAs($this->owner);
});

it('returns comparison across 5 completed versions with all 5 dimension scores (?include=comparison)', function () {
    foreach ([1, 2, 3, 4, 5] as $version) {
        Evaluation::create([
            'project_id' => $this->project->id,
            'version' => $version,
            'status' => EvaluationStatus::COMPLETED,
            'overall_score' => $version * 10,
            'result' => comparisonResultFor(comparisonScoresFor($version * 10)),
            'completed_at' => now()->subMinutes(5 - $version),
        ]);
    }

    $this->getJson("/api/projects/{$this->project->id}/evaluations?include=comparison")
        ->assertStatus(200)
        ->assertJsonCount(5, 'data.comparison')
        ->assertJsonPath('data.comparison.0.version', 5)
        ->assertJsonPath('data.comparison.0.dimensions.technical_quality', 50)
        ->assertJsonPath('data.comparison.0.dimensions.innovation', 50)
        ->assertJsonPath('data.comparison.0.dimensions.market_viability', 50)
        ->assertJsonPath('data.comparison.0.dimensions.team_completeness', 50)
        ->assertJsonPath('data.comparison.0.dimensions.documentation', 50)
        ->assertJsonPath('data.comparison.4.version', 1);
});

it('drops the oldest version when a 6th evaluation succeeds (FR-229)', function () {
    foreach ([1, 2, 3, 4, 5, 6] as $version) {
        Evaluation::create([
            'project_id' => $this->project->id,
            'version' => $version,
            'status' => EvaluationStatus::COMPLETED,
            'overall_score' => $version * 10,
            'result' => comparisonResultFor(comparisonScoresFor($version * 10)),
            'completed_at' => now()->subMinutes(6 - $version),
        ]);
    }

    $this->getJson("/api/projects/{$this->project->id}/evaluations?include=comparison")
        ->assertStatus(200)
        ->assertJsonCount(5, 'data.comparison')
        ->assertJsonPath('data.comparison.0.version', 6)
        ->assertJsonPath('data.comparison.4.version', 2);
});

it('omits comparison for non-owners (US-023 — owner only)', function () {
    Evaluation::create([
        'project_id' => $this->project->id,
        'version' => 1,
        'status' => EvaluationStatus::COMPLETED,
        'overall_score' => 70,
        'result' => comparisonResultFor(comparisonScoresFor(70)),
        'completed_at' => now()->subMinute(),
    ]);

    $investor = User::factory()->investor()->create();
    Sanctum::actingAs($investor);

    $this->getJson("/api/projects/{$this->project->id}/evaluations?include=comparison")
        ->assertStatus(200)
        ->assertJsonMissingPath('data.comparison');
});
