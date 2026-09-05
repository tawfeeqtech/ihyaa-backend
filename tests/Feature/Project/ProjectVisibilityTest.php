<?php

namespace Tests\Feature\Project;

use App\Enums\EvaluationStatus;
use App\Enums\ProjectState;
use App\Enums\ProjectStatus;
use App\Enums\VisibilityLevel;
use App\Models\Category;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * الإفصاح عن تقرير AI — SRS-F05-05 · US-038 AC2 · T127.
 * المسجّل (غير المالك) يرى الأبعاد + الرادار دائماً؛ الزائر يرى الكلي فقط؛
 * المالك/المستثمر بعد اتفاق يرون كل شيء.
 */

/** تقييم مكتمل بأبعاد — لعرض مصفوفة التقرير عبر GET /api/projects/{id}. */
function attachCompletedEvaluation(Project $project): Evaluation
{
    return Evaluation::create([
        'project_id' => $project->id,
        'version' => 1,
        'status' => EvaluationStatus::COMPLETED,
        'overall_score' => 70,
        'confidence_score' => 80,
        'result' => [
            'dimensions' => [
                'technical_quality' => 70,
                'commercial_viability' => 72,
                'market_opportunity' => 74,
                'team_capability' => 68,
                'financial_projections' => 66,
            ],
            'gap_analysis' => ['نقاط ضعف' => 'لا توجد'],
            'recommendations' => ['يُضاف توثيق السوق'],
        ],
    ]);
}

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->investor = User::factory()->investor()->create();
});

it('gives a registered non-owner dimensions access to a default-visibility project (T127 / US-038 AC2)', function () {
    // بلا visibility_level → default من migration = 2 (REGISTERED) — T127
    $project = Project::create([
        'user_id' => $this->owner->id,
        'category_id' => Category::factory()->create()->id,
        'title' => 'مشروع افتراضي للتحقق من الإفصاح',
        'description' => 'وصف المشروع التفصيلي للاختبار — وصف المشروع التفصيلي للاختبار — وصف المشروع التفصيلي للاختبار.',
        'status' => ProjectState::NEEDS_FUNDING,
        'publication_status' => ProjectStatus::PUBLISHED,
    ]);
    attachCompletedEvaluation($project);

    Sanctum::actingAs($this->investor);

    $this->getJson("/api/projects/{$project->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.report_access', 'dimensions')
        ->assertJsonPath('data.evaluation.scores.technical_quality', 70)
        ->assertJsonMissingPath('data.evaluation.gap_analysis');
});

it('never returns none for a registered non-owner, even at AFTER_AGREEMENT visibility (T127 regression)', function () {
    $project = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'visibility_level' => VisibilityLevel::AFTER_AGREEMENT,
    ]);
    attachCompletedEvaluation($project);

    Sanctum::actingAs($this->investor);

    $this->getJson("/api/projects/{$project->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.report_access', 'dimensions')
        ->assertJsonPath('data.evaluation.scores.technical_quality', 70)
        ->assertJsonMissingPath('data.evaluation.gap_analysis');
});

it('limits a visitor to the overall score only (US-029)', function () {
    $project = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'visibility_level' => VisibilityLevel::VISITOR,
    ]);
    attachCompletedEvaluation($project);

    // بلا Sanctum → زائر غير مسجّل
    $this->getJson("/api/projects/{$project->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.report_access', 'overall')
        ->assertJsonPath('data.evaluation.overall_score', 70)
        ->assertJsonMissingPath('data.evaluation.scores');
});
