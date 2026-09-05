<?php

namespace Tests\Feature\AiAgent;

use App\Enums\EvaluationStatus;
use App\Models\AiAgentArtifact;
use App\Models\Category;
use App\Models\Evaluation;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Services\AiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * بوابة AI وهمية قابلة للتبديل — T122: تُفشِل تحليل SWOT بإرجاع مخطط غير صالح.
 * عند fail=false تعود للوضع الافتراضي (mock صالح).
 */
final class SwotFakeGateway extends AiGateway
{
    public bool $fail = false;

    public function analyzeStructured(string $type, string $prompt): array
    {
        if ($this->fail) {
            return [
                'strengths' => ['نقطة واحدة فقط'],
                'weaknesses' => [],
                'opportunities' => [],
                'threats' => [],
                'recommendations' => [],
            ];
        }

        return parent::analyzeStructured($type, $prompt);
    }
}

/** مشروع منشور لمالكه + تقييم مكتمل (الشرط المسبق لتحليل وكيل AI). */
function aiEvaluatedProject(array $attrs = []): Project
{
    $owner = User::factory()->ideaOwner()->create();
    $project = Project::factory()->published()->create(array_merge(['user_id' => $owner->id], $attrs));

    Evaluation::create([
        'project_id' => $project->id,
        'version' => 1,
        'status' => EvaluationStatus::COMPLETED,
        'overall_score' => 70.0,
        'result' => [
            'dimensions' => [
                'technical_quality' => ['score' => 70, 'strengths' => ['أساس تقني جيد'], 'weaknesses' => ['اختبارات قليلة']],
                'innovation' => ['score' => 70, 'strengths' => ['فكرة جديدة'], 'weaknesses' => ['تشابه جزئي']],
                'market_viability' => ['score' => 70, 'strengths' => ['سوق نامٍ'], 'weaknesses' => ['تسعير غير واضح']],
                'team_completeness' => ['score' => 70, 'strengths' => ['تنوع مهارات'], 'weaknesses' => ['نقص تسويق']],
                'documentation' => ['score' => 70, 'strengths' => ['توثيق جيد'], 'weaknesses' => ['مخطط ناقص']],
            ],
            'gap_analysis' => [
                'technical_gaps' => ['تحسين الاختبارات'],
                'market_gaps' => ['دراسة المنافسين'],
                'team_gaps' => [],
                'documentation_gaps' => ['خارطة طريق'],
            ],
            'required_skills' => ['PHP', 'Laravel', 'Product Management'],
            'recommendations' => ['بدء الاختبار مع مستخدمين'],
            'warnings' => [],
        ],
    ]);

    return $project;
}

beforeEach(function () {
    config(['scout.driver' => 'null']);
    config(['ai.mock' => true]);
});

// ——————————————————————— T101 · POST /ai/analyze — comparison ———————————————————————

it('runs comparison analysis asynchronously and stores a completed artifact (T101)', function () {
    $project = aiEvaluatedProject();
    Sanctum::actingAs($project->owner);

    $this->postJson("/api/ai/analyze/{$project->id}", ['analysis_type' => 'comparison'])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'processing')
        ->assertJsonPath('data.analysis_type', 'comparison')
        ->assertJsonPath('data.version', 1);

    $artifact = AiAgentArtifact::where('project_id', $project->id)
        ->where('analysis_type', 'comparison')->first();

    expect($artifact)->not->toBeNull();
    expect($artifact->status->value)->toBe('completed');
    expect($artifact->artifact_data)->toHaveKey('competitors');
    expect($artifact->artifact_data['insufficient_data_note'])->toBeTrue();
});

it('includes competitors and clears the note when 3+ evaluated competitors exist (T101/T105)', function () {
    $category = Category::factory()->create();
    $project = aiEvaluatedProject(['category_id' => $category->id]);

    foreach (range(1, 3) as $i) {
        $competitor = Project::factory()->published()->create([
            'user_id' => User::factory()->ideaOwner()->create()->id,
            'category_id' => $category->id,
            'tags' => array_merge((array) $project->tags, ['shared-tag']),
            'ai_score' => 65.0 + $i,
        ]);
        Evaluation::create([
            'project_id' => $competitor->id,
            'version' => 1,
            'status' => EvaluationStatus::COMPLETED,
            'overall_score' => $competitor->ai_score,
        ]);
    }

    Sanctum::actingAs($project->owner);

    $this->postJson("/api/ai/analyze/{$project->id}", ['analysis_type' => 'comparison'])
        ->assertStatus(202);

    $artifact = AiAgentArtifact::where('project_id', $project->id)
        ->where('analysis_type', 'comparison')->first();

    expect($artifact->artifact_data['count'])->toBe(3);
    expect($artifact->artifact_data['insufficient_data_note'])->toBeFalse();
    expect(count($artifact->artifact_data['competitors']))->toBe(3);
});

// ——————————————————————— T103 · المتطلبات المسبقة ———————————————————————

it('rejects a non-owner with 403 (T103)', function () {
    $project = aiEvaluatedProject();
    Sanctum::actingAs(User::factory()->ideaOwner()->create());

    $this->postJson("/api/ai/analyze/{$project->id}", ['analysis_type' => 'swot'])
        ->assertStatus(403);
});

it('rejects an unevaluated project with 422 (T103)', function () {
    $owner = User::factory()->ideaOwner()->create();
    $project = Project::factory()->published()->create(['user_id' => $owner->id]);
    Sanctum::actingAs($owner);

    $this->postJson("/api/ai/analyze/{$project->id}", ['analysis_type' => 'swot'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'PROJECT_NOT_EVALUATED');
});

it('rejects an invalid analysis_type with 422 (T102)', function () {
    $project = aiEvaluatedProject();
    Sanctum::actingAs($project->owner);

    $this->postJson("/api/ai/analyze/{$project->id}", ['analysis_type' => 'market'])
        ->assertStatus(422);
});

// ——————————————————————— T121 · التزامن ———————————————————————

it('returns 409 ANALYSIS_IN_PROGRESS when analysis is already running (T121)', function () {
    $project = aiEvaluatedProject();
    Sanctum::actingAs($project->owner);

    $lock = Cache::lock("ai-analysis:{$project->id}:swot", 600);
    $lock->get();

    $this->postJson("/api/ai/analyze/{$project->id}", ['analysis_type' => 'swot'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'ANALYSIS_IN_PROGRESS');

    $lock->release();

    $this->postJson("/api/ai/analyze/{$project->id}", ['analysis_type' => 'swot'])
        ->assertStatus(202);
});

// ——————————————————————— T122 · الفشل وإعادة المحاولة ———————————————————————

it('marks failed, keeps previous version, and creates a new version on retry (T122)', function () {
    $fake = new SwotFakeGateway();
    $fake->fail = false;
    app()->instance(AiGateway::class, $fake);

    $project = aiEvaluatedProject();
    Sanctum::actingAs($project->owner);

    // v1 ناجح
    $this->postJson("/api/ai/analyze/{$project->id}", ['analysis_type' => 'swot'])->assertStatus(202);
    $v1 = AiAgentArtifact::where('project_id', $project->id)->where('analysis_type', 'swot')->where('version', 1)->first();
    expect($v1->status->value)->toBe('completed');

    // v2 يفشل — النموذج يرد مخرجات لا تطابق المخطط
    $fake->fail = true;
    $this->postJson("/api/ai/analyze/{$project->id}", ['analysis_type' => 'swot'])->assertStatus(202);
    $v2 = AiAgentArtifact::where('project_id', $project->id)->where('analysis_type', 'swot')->where('version', 2)->first();
    expect($v2->status->value)->toBe('failed');
    expect($v2->error_message)->not->toBeNull();
    expect($v1->fresh()->status->value)->toBe('completed');

    // إعادة المحاولة → إصدار جديد ناجح + إشعار analysis_completed
    $fake->fail = false;
    $this->postJson("/api/ai/analyze/{$project->id}", ['analysis_type' => 'swot'])->assertStatus(202);
    $v3 = AiAgentArtifact::where('project_id', $project->id)->where('analysis_type', 'swot')->where('version', 3)->first();
    expect($v3->status->value)->toBe('completed');

    expect(Notification::where('type', 'analysis_completed')
        ->where('user_id', $project->user_id)->exists())->toBeTrue();
});

// ——————————————————————— T115/T116 · القراءة ———————————————————————

it('allows the owner to read an artifact and latest-per-type (T115/T116)', function () {
    $project = aiEvaluatedProject();
    Sanctum::actingAs($project->owner);

    $this->postJson("/api/ai/analyze/{$project->id}", ['analysis_type' => 'swot'])->assertStatus(202);
    $artifact = AiAgentArtifact::where('project_id', $project->id)->where('analysis_type', 'swot')->first();

    $this->getJson("/api/ai/analysis/{$artifact->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.analysis_type', 'swot')
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.artifact_data.summary', 'تحليل SWOT شامل معتمد على آخر تقييم رسمي للمشروع.');

    $this->getJson("/api/projects/{$project->id}/ai-analysis")
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.analysis_type', 'swot');
});

it('forbids non-owners from reading artifacts (T116)', function () {
    $project = aiEvaluatedProject();
    $artifact = AiAgentArtifact::create([
        'project_id' => $project->id,
        'analysis_type' => 'swot',
        'artifact_data' => [],
        'version' => 1,
        'status' => 'completed',
    ]);

    Sanctum::actingAs(User::factory()->ideaOwner()->create());

    $this->getJson("/api/ai/analysis/{$artifact->id}")->assertStatus(403);
});

// ——————————————————————— T118 · التصدير PDF ———————————————————————

it('exports a PDF for a completed analysis (T118)', function () {
    $project = aiEvaluatedProject();
    Sanctum::actingAs($project->owner);

    $this->postJson("/api/ai/analyze/{$project->id}", ['analysis_type' => 'swot'])->assertStatus(202);
    $artifact = AiAgentArtifact::where('project_id', $project->id)->where('analysis_type', 'swot')->first();

    $response = $this->get("/api/ai/analysis/{$artifact->id}/export");
    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

it('returns 409 ANALYSIS_INCOMPLETE when exporting an incomplete analysis (T118)', function () {
    $project = aiEvaluatedProject();
    $artifact = AiAgentArtifact::create([
        'project_id' => $project->id,
        'analysis_type' => 'swot',
        'artifact_data' => [],
        'version' => 1,
        'status' => 'processing',
    ]);
    Sanctum::actingAs($project->owner);

    $this->getJson("/api/ai/analysis/{$artifact->id}/export")
        ->assertStatus(409)
        ->assertJsonPath('code', 'ANALYSIS_INCOMPLETE');
});
