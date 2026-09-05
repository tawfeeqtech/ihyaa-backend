<?php

namespace Tests\Feature\AiAgent;

use App\Enums\EvaluationStatus;
use App\Models\AiAgentArtifact;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use App\Services\AiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);
    config(['ai.mock' => true]);
    app()->instance(AiGateway::class, new AiGateway());
});

function qualityProject(): Project
{
    $owner = User::factory()->ideaOwner()->create();
    $project = Project::factory()->published()->create(['user_id' => $owner->id]);

    Evaluation::create([
        'project_id' => $project->id,
        'version' => 1,
        'status' => EvaluationStatus::COMPLETED,
        'overall_score' => 72.0,
        'result' => [
            'dimensions' => [
                'technical_quality' => ['score' => 74, 'strengths' => ['أساس تقني جيد'], 'weaknesses' => ['اختبارات قليلة']],
                'innovation' => ['score' => 68, 'strengths' => ['فكرة جديدة'], 'weaknesses' => ['تشابه جزئي']],
                'market_viability' => ['score' => 65, 'strengths' => ['سوق نامٍ'], 'weaknesses' => ['تسعير غير واضح']],
                'team_completeness' => ['score' => 70, 'strengths' => ['تنوع مهارات'], 'weaknesses' => ['نقص تسويق']],
                'documentation' => ['score' => 61, 'strengths' => ['توثيق جيد'], 'weaknesses' => ['مخطط ناقص']],
            ],
            'gap_analysis' => ['technical_gaps' => ['تحسين الاختبارات'], 'market_gaps' => [], 'team_gaps' => [], 'documentation_gaps' => []],
            'required_skills' => ['PHP'],
            'recommendations' => ['بدء الاختبار'],
        ],
    ]);

    return $project;
}

it('keeps analysis quality consistent between Arabic and English (T110)', function () {
    $project = qualityProject();
    Sanctum::actingAs($project->owner);

    $this->postJson("/api/ai/analyze/{$project->id}", [
        'analysis_type' => 'swot',
        'language' => 'ar',
    ])->assertStatus(202);

    $this->postJson("/api/ai/analyze/{$project->id}", [
        'analysis_type' => 'swot',
        'language' => 'en',
    ])->assertStatus(202);

    $ar = AiAgentArtifact::where('project_id', $project->id)->where('analysis_type', 'swot')->where('version', 1)->first();
    $en = AiAgentArtifact::where('project_id', $project->id)->where('analysis_type', 'swot')->where('version', 2)->first();

    expect($ar->status->value)->toBe('completed');
    expect($en->status->value)->toBe('completed');
    expect($ar->language)->toBe('ar');
    expect($en->language)->toBe('en');

    $arItems = count($ar->artifact_data['strengths']) + count($ar->artifact_data['weaknesses'])
        + count($ar->artifact_data['opportunities']) + count($ar->artifact_data['threats'])
        + count($ar->artifact_data['recommendations']);

    $enItems = count($en->artifact_data['strengths']) + count($en->artifact_data['weaknesses'])
        + count($en->artifact_data['opportunities']) + count($en->artifact_data['threats'])
        + count($en->artifact_data['recommendations']);

    expect($arItems)->toBeGreaterThanOrEqual(19);
    $diff = $arItems > 0 ? abs($arItems - $enItems) / $arItems : 0;
    expect($diff)->toBeLessThan(0.05);
});
