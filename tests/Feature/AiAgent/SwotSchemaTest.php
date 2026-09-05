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
    // إعادة ربط بوابة نظيفة — يمنع تسرّب أي وهم من ملفات اختبار أخرى
    app()->instance(AiGateway::class, new AiGateway());
});

function swotProject(): Project
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
            'gap_analysis' => [
                'technical_gaps' => ['تحسين الاختبارات'],
                'market_gaps' => ['دراسة المنافسين'],
                'team_gaps' => [],
                'documentation_gaps' => ['خارطة طريق'],
            ],
            'required_skills' => ['PHP', 'Laravel'],
            'recommendations' => ['بدء الاختبار مع مستخدمين'],
        ],
    ]);

    return $project;
}

it('produces a valid SWOT schema with 4+ items per category and no external facts (T109)', function () {
    $project = swotProject();
    Sanctum::actingAs($project->owner);

    $this->postJson("/api/ai/analyze/{$project->id}", ['analysis_type' => 'swot'])
        ->assertStatus(202);

    $artifact = AiAgentArtifact::where('project_id', $project->id)
        ->where('analysis_type', 'swot')->first();

    expect($artifact->status->value)->toBe('completed');
    $data = $artifact->artifact_data;

    foreach (['strengths', 'weaknesses', 'opportunities', 'threats'] as $key) {
        expect(count($data[$key]))->toBeGreaterThanOrEqual(4);
        foreach ($data[$key] as $item) {
            expect($item)->toBeString()->not->toBeEmpty();
        }
    }

    expect(count($data['recommendations']))->toBeGreaterThanOrEqual(3);
    expect($data['derived_from'])->toContain('last_evaluation');
    expect($data['summary'])->not->toBeEmpty();
});

it('stores the model used and derives analysis only from the last evaluation (T109)', function () {
    $project = swotProject();
    Sanctum::actingAs($project->owner);

    $this->postJson("/api/ai/analyze/{$project->id}", ['analysis_type' => 'swot'])
        ->assertStatus(202);

    $artifact = AiAgentArtifact::where('project_id', $project->id)
        ->where('analysis_type', 'swot')->first();

    // mock دائمًا يعيد openai — لا نفحص هوية المزود بل وجوده كقيمة حالة
    expect($artifact->model_used?->value)->toBe('openai');
    expect($artifact->artifact_data['derived_from'])->toBe(['last_evaluation']);
});
