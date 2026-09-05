<?php

namespace Tests\Feature\Project;

use App\Enums\EvaluationStatus;
use App\Enums\ProjectStatus;
use App\Enums\VisibilityLevel;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * إفصاح تفاصيل المشروع عبر GET /api/projects/{id} — SRS-F05-05 · T094/T099.
 * يكمل ProjectVisibilityTest بإضافة بيانات المالك/الفريق/الإعداد المخزّن والمسودات والحذف.
 */

/** تقييم مكتمل بأبعاد وتحليل — لمستوى إفصاح كامل. */
function attachEvaluationToProject(Project $project): Evaluation
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
            'gap_analysis' => ['نقاط الضعف' => ['ضعف التوثيق التجاري']],
            'recommendations' => ['توثيق السوق المستهدف'],
            'required_skills' => ['تحليل مالي'],
            'warnings' => [],
        ],
    ]);
}

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->investor = User::factory()->investor()->create();
});

it('gives the owner full report access with gap analysis and team data', function () {
    $project = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'visibility_level' => VisibilityLevel::REGISTERED,
        'team' => [['name' => 'أحمد', 'role' => 'مطور']],
        'ai_score' => 70,
    ]);
    attachEvaluationToProject($project);

    Sanctum::actingAs($this->owner);

    $this->getJson("/api/projects/{$project->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.report_access', 'full')
        ->assertJsonPath('data.evaluation_status', 'completed')
        ->assertJsonPath('data.evaluation.scores.technical_quality', 70)
        ->assertJsonPath('data.evaluation.gap_analysis', ['نقاط الضعف' => ['ضعف التوثيق التجاري']])
        ->assertJsonPath('data.evaluation.recommendations.0', 'توثيق السوق المستهدف')
        ->assertJsonPath('data.stored_visibility_level', VisibilityLevel::REGISTERED->value)
        ->assertJsonPath('data.team.0.name', 'أحمد')
        ->assertJsonPath('data.owner.email', $this->owner->email);
});

it('limits a visitor to the overall score and hides owner private data', function () {
    $project = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'visibility_level' => VisibilityLevel::VISITOR,
        'team' => [['name' => 'أحمد', 'role' => 'مطور']],
    ]);
    attachEvaluationToProject($project);

    $this->getJson("/api/projects/{$project->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.report_access', 'overall')
        ->assertJsonPath('data.evaluation.overall_score', 70)
        ->assertJsonMissingPath('data.evaluation.scores')
        ->assertJsonPath('data.owner', null)
        ->assertJsonMissingPath('data.team')
        ->assertJsonMissingPath('data.stored_visibility_level');
});

it('gives a registered non-owner dimensions without owner private data', function () {
    $project = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'visibility_level' => VisibilityLevel::REGISTERED,
        'team' => [['name' => 'أحمد', 'role' => 'مطور']],
    ]);
    attachEvaluationToProject($project);

    Sanctum::actingAs($this->investor);

    $this->getJson("/api/projects/{$project->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.report_access', 'dimensions')
        ->assertJsonPath('data.evaluation.scores.technical_quality', 70)
        ->assertJsonMissingPath('data.evaluation.gap_analysis')
        ->assertJsonPath('data.owner', null)
        ->assertJsonMissingPath('data.team')
        ->assertJsonMissingPath('data.stored_visibility_level');
});

it('marks an unevaluated project as evaluation_status pending (T148)', function () {
    $project = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'ai_score' => null,
    ]);

    $this->getJson("/api/projects/{$project->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.ai_score', null)
        ->assertJsonPath('data.evaluation_status', 'pending');
});

it('hides a draft project from non-owners', function () {
    $project = Project::factory()->draft()->create([
        'user_id' => $this->owner->id,
        'visibility_level' => VisibilityLevel::REGISTERED,
    ]);

    Sanctum::actingAs($this->investor);

    $this->getJson("/api/projects/{$project->id}")->assertStatus(404);
});

it('shows a draft project to its owner', function () {
    $project = Project::factory()->draft()->create([
        'user_id' => $this->owner->id,
        'visibility_level' => VisibilityLevel::REGISTERED,
    ]);

    Sanctum::actingAs($this->owner);

    $this->getJson("/api/projects/{$project->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.publication_status', ProjectStatus::DRAFT->value);
});

it('returns 404 for a soft-deleted project', function () {
    $project = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $project->delete();

    $this->getJson("/api/projects/{$project->id}")->assertStatus(404);
});
