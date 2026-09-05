<?php

namespace Tests\Feature\Reports;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ReportFixtures;

uses(RefreshDatabase::class, ReportFixtures::class);

/**
 * بيانات تقرير AI — T090 (contracts/report-api.md §1 · US-025-S2).
 * الرادار يُبنى من `radar_chart.axes` فقط (مصدر واحد = الأبعاد المخزَّنة) —
 * والتقرير الجزئي يعرض الأبعاد المكتملة فقط.
 */
beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);
});

it('builds radar axes from the stored dimensions in canonical order (T090)', function () {
    $evaluation = $this->makeCompletedEvaluation($this->project);

    Sanctum::actingAs($this->owner);

    $this->getJson("/api/projects/{$this->project->id}/evaluations/{$evaluation->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.access_level', 'EX')
        ->assertJsonCount(5, 'data.radar_chart.axes')
        ->assertJsonPath('data.radar_chart.axes.0.dimension', 'technical_quality')
        ->assertJsonPath('data.radar_chart.axes.0.value', 71.2)
        ->assertJsonPath('data.radar_chart.axes.0.label_ar', 'الجودة التقنية')
        ->assertJsonPath('data.radar_chart.axes.1.dimension', 'innovation')
        ->assertJsonPath('data.radar_chart.axes.2.dimension', 'market_viability')
        ->assertJsonPath('data.radar_chart.axes.3.dimension', 'team_completeness')
        ->assertJsonPath('data.radar_chart.axes.4.dimension', 'documentation');
});

it('exposes only completed dimensions on a partial report (3/5) (T090)', function () {
    $evaluation = $this->makePartialEvaluation($this->project);

    Sanctum::actingAs($this->owner);

    $this->getJson("/api/projects/{$this->project->id}/evaluations/{$evaluation->id}")
        ->assertStatus(200)
        ->assertJsonCount(3, 'data.radar_chart.axes')
        ->assertJsonPath('data.radar_chart.axes.0.dimension', 'technical_quality')
        ->assertJsonPath('data.radar_chart.axes.1.dimension', 'innovation')
        ->assertJsonPath('data.radar_chart.axes.2.dimension', 'documentation')
        ->assertJsonMissingPath('data.evaluation.dimensions.market_viability')
        ->assertJsonMissingPath('data.evaluation.dimensions.team_completeness')
        ->assertJsonPath('data.evaluation.partial_dimensions.0', 'market_viability')
        ->assertJsonPath('data.evaluation.partial_dimensions.1', 'team_completeness');
});

it('returns 404 for evaluations with no report (failed/pending) (report-api §5)', function () {
    $evaluation = \App\Models\Evaluation::create([
        'project_id' => $this->project->id,
        'version' => 1,
        'status' => \App\Enums\EvaluationStatus::FAILED,
        'error_log' => ['type' => 'all_providers_failed'],
    ]);

    Sanctum::actingAs($this->owner);

    $this->getJson("/api/projects/{$this->project->id}/evaluations/{$evaluation->id}")
        ->assertStatus(404);
});

it('returns 404 when the evaluation belongs to another project (no leak)', function () {
    $other = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $evaluation = $this->makeCompletedEvaluation($other);

    Sanctum::actingAs($this->owner);

    $this->getJson("/api/projects/{$this->project->id}/evaluations/{$evaluation->id}")
        ->assertStatus(404);
});
