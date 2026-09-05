<?php

namespace Tests\Feature\Reports;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ReportFixtures;

uses(RefreshDatabase::class, ReportFixtures::class);

/**
 * تحليل الفجوات والتوصيات — T096 (contracts/report-api.md §1 · US-026).
 * أربع فئات فجوات + توصيات بثلاثة آفاق + اتساق الفجوة مع نقاط ضعف البعد المرافق.
 */
beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $this->evaluation = $this->makeCompletedEvaluation($this->project);

    Sanctum::actingAs($this->owner);
});

it('returns the 4 gap categories with fixed keys (T096)', function () {
    $this->getJson("/api/projects/{$this->project->id}/evaluations/{$this->evaluation->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.evaluation.gap_analysis.technical_gaps.0', 'لا توجد اختبارات آلية')
        ->assertJsonPath('data.evaluation.gap_analysis.market_gaps.0', 'لا نموذج إيرادات موصوف')
        ->assertJsonPath('data.evaluation.gap_analysis.team_gaps.0', 'لا مسوّق في الفريق')
        ->assertJsonPath('data.evaluation.gap_analysis.documentation_gaps', []);
});

it('returns recommendations across the 3 horizons (T096)', function () {
    $this->getJson("/api/projects/{$this->project->id}/evaluations/{$this->evaluation->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.evaluation.recommendations.immediate.0', 'أضف README تقنياً بخطوات التشغيل')
        ->assertJsonPath('data.evaluation.recommendations.short_term.0', 'حدد نموذج الإيرادات في صفحة المشروع')
        ->assertJsonPath('data.evaluation.recommendations.long_term.0', 'أنشئ CI/CD مع تغطية اختبارات');
});

it('keeps each gap consistent with the weaknesses of its companion dimension (T096)', function () {
    $response = $this->getJson("/api/projects/{$this->project->id}/evaluations/{$this->evaluation->id}")
        ->assertStatus(200)
        ->json('data');

    $map = [
        'technical_gaps' => 'technical_quality',
        'market_gaps' => 'market_viability',
        'team_gaps' => 'team_completeness',
        'documentation_gaps' => 'documentation',
    ];

    foreach ($map as $gapKey => $dimensionKey) {
        $gaps = $response['evaluation']['gap_analysis'][$gapKey] ?? [];
        $weaknesses = $response['evaluation']['dimensions'][$dimensionKey]['weaknesses'] ?? [];

        // كل فجوة في البعد المرافق تظهر كبند فجوة (الاتساق — لا تضارب مصادر).
        foreach ($weaknesses as $weakness) {
            $this->assertTrue(
                in_array($weakness, $gaps, true),
                "فجوة «{$weakness}» يجب أن تظهر في {$gapKey} (مطابقة ضعف {$dimensionKey})"
            );
        }
    }
});

it('hides gap analysis and recommendations from L2 non-owners (US-029)', function () {
    $investor = User::factory()->investor()->create();
    Sanctum::actingAs($investor);

    $this->getJson("/api/projects/{$this->project->id}/evaluations/{$this->evaluation->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.access_level', 'L2')
        ->assertJsonMissingPath('data.evaluation.gap_analysis')
        ->assertJsonMissingPath('data.evaluation.recommendations')
        ->assertJsonMissingPath('data.evaluation.required_skills')
        ->assertJsonMissingPath('data.evaluation.warnings')
        ->assertJsonMissingPath('data.swot')
        ->assertJsonMissingPath('data.export');
});
