<?php

namespace Tests\Feature\Reports;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ReportFixtures;

uses(RefreshDatabase::class, ReportFixtures::class);

/**
 * مصفوفة الإفصاح عن تقرير AI — T110 (contracts/report-api.md §3 · US-029 · FR-236/237).
 * كل مستوى يرى محتواه تماماً: L2 بلا فجوات/توصيات/مهارات/تصدير، وL3/EX/AD كل شيء.
 * الحماية الفعلية على الخادم — طلب حقل محمي صراحة = 403 لا إهمال.
 */
beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $this->evaluation = $this->makeCompletedEvaluation($this->project);
});

it('returns 401 for guests on the report data endpoint (L1) (T110)', function () {
    $this->getJson("/api/projects/{$this->project->id}/evaluations/{$this->evaluation->id}")
        ->assertStatus(401);
});

it('gives a registered non-owner exactly L2 (overall + dimensions + radar) (T110)', function () {
    $investor = User::factory()->investor()->create();
    Sanctum::actingAs($investor);

    $this->getJson("/api/projects/{$this->project->id}/evaluations/{$this->evaluation->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.access_level', 'L2')
        ->assertJsonPath('data.evaluation.overall_score', 72.4)
        ->assertJsonPath('data.evaluation.dimensions.technical_quality.score', 71.2)
        ->assertJsonPath('data.evaluation.dimensions.technical_quality.sub_scores.code_structure', 78)
        ->assertJsonCount(5, 'data.radar_chart.axes')
        // L2 لا يرى النصية ولا التحليل ولا التصدير
        ->assertJsonMissingPath('data.evaluation.dimensions.technical_quality.strengths')
        ->assertJsonMissingPath('data.evaluation.gap_analysis')
        ->assertJsonMissingPath('data.evaluation.recommendations')
        ->assertJsonMissingPath('data.evaluation.required_skills')
        ->assertJsonMissingPath('data.evaluation.warnings')
        ->assertJsonMissingPath('data.swot')
        ->assertJsonMissingPath('data.export');
});

it('gives a post-agreement investor exactly L3 (everything) (T110)', function () {
    $investor = User::factory()->investor()->create();
    $this->acceptInterest($this->project, $investor);

    Sanctum::actingAs($investor);

    $this->getJson("/api/projects/{$this->project->id}/evaluations/{$this->evaluation->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.access_level', 'L3')
        ->assertJsonPath('data.evaluation.gap_analysis.technical_gaps.0', 'لا توجد اختبارات آلية')
        ->assertJsonPath('data.evaluation.recommendations.immediate.0', 'أضف README تقنياً بخطوات التشغيل')
        ->assertJsonPath('data.evaluation.required_skills.0', 'UI/UX Designer')
        ->assertJsonPath('data.evaluation.warnings.0', 'معلومات الفريق غير كافية — قد يؤثر على دقة بُعد اكتمال الفريق')
        ->assertJsonPath('data.swot.strengths', [])
        ->assertJsonPath('data.export.allowed', true)
        ->assertJsonPath('data.team_meta.has_team_data', false);
});

it('always gives the project owner EX (full) — guaranteed exception (T110 · SRS-F05-05)', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson("/api/projects/{$this->project->id}/evaluations/{$this->evaluation->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.access_level', 'EX')
        ->assertJsonPath('data.evaluation.gap_analysis.market_gaps.0', 'لا نموذج إيرادات موصوف')
        ->assertJsonPath('data.export.allowed', true);
});

it('gives an admin AD (full) (T110)', function () {
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin);

    $this->getJson("/api/projects/{$this->project->id}/evaluations/{$this->evaluation->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.access_level', 'AD')
        ->assertJsonPath('data.evaluation.gap_analysis.team_gaps.0', 'لا مسوّق في الفريق')
        ->assertJsonPath('data.export.allowed', true);
});

it('rejects an explicit ?include=gap_analysis at L2 with 403 (T110 · no silent omission)', function () {
    $investor = User::factory()->investor()->create();
    Sanctum::actingAs($investor);

    $this->getJson("/api/projects/{$this->project->id}/evaluations/{$this->evaluation->id}?include=gap_analysis")
        ->assertStatus(403)
        ->assertJsonPath('code', 'DISCLOSURE_LEVEL_INSUFFICIENT');
});

it('allows an explicit ?include=gap_analysis at full level (EX) (T110)', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson("/api/projects/{$this->project->id}/evaluations/{$this->evaluation->id}?include=gap_analysis")
        ->assertStatus(200)
        ->assertJsonPath('data.evaluation.gap_analysis.technical_gaps.0', 'لا توجد اختبارات آلية');
});
