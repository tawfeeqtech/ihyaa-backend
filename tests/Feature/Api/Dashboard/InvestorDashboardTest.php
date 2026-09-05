<?php

namespace Tests\Feature\Api\Dashboard;

use App\Enums\EvaluationStatus;
use App\Enums\InterestStatus;
use App\Models\Agreement;
use App\Models\Category;
use App\Models\Evaluation;
use App\Models\Interest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * GET /api/dashboard/investor — T079 · US-056..060 (dashboard-api.md §2 · SRS-API-39).
 *
 * الدور: investor فقط (403 ERR-403-02 لصاحب الفكرة) · البنية:
 * { kpis, profile_complete, suggestions(≤10), sent_interests, saved_projects, updates_feed }.
 * الدستور I: Level 1 فقط في الاقتراحات — لا درجات أبعاد · الدستور III: RTL عربي.
 */

/** ينشئ تصنيفاً بمعرّف صريح. */
function invCategory(string $slug, string $nameAr): Category
{
    return Category::factory()->create(['slug' => $slug, 'name_ar' => $nameAr, 'name_en' => $nameAr]);
}

beforeEach(function () {
    config(['scout.driver' => 'null']);
    $this->owner = User::factory()->ideaOwner()->create();
    $this->investor = User::factory()->investor()->create();
    Sanctum::actingAs($this->investor);
});

it('returns the full investor dashboard structure (kpis · suggestions · interests · saved · updates)', function () {
    $fintech = invCategory('fintech', 'التقنية المالية');
    $project = Project::factory()->published()->create(['user_id' => $this->owner->id, 'category_id' => $fintech->id, 'ai_score' => 74]);
    $this->investor->savedProjects()->create(['project_id' => $project->id]);

    // تغذية تحديثات: تقييمان بدرجتين مختلفتين → حدث evaluation_updated.
    Evaluation::create(['project_id' => $project->id, 'version' => 1, 'status' => EvaluationStatus::COMPLETED->value, 'overall_score' => 62, 'completed_at' => now()->subDay()]);
    Evaluation::create(['project_id' => $project->id, 'version' => 2, 'status' => EvaluationStatus::COMPLETED->value, 'overall_score' => 81, 'completed_at' => now()]);

    $response = $this->getJson('/api/dashboard/investor');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.kpis.sent_requests', 0)
        ->assertJsonPath('data.kpis.followed_projects', 1)
        ->assertJsonPath('data.profile_complete', true)
        ->assertJsonCount(1, 'data.suggestions')
        ->assertJsonCount(1, 'data.saved_projects')
        ->assertJsonCount(1, 'data.updates_feed');

    // بنية الحدث.
    $response->assertJsonPath('data.updates_feed.0.type', 'evaluation_updated')
        ->assertJsonPath('data.updates_feed.0.old_score', 62)
        ->assertJsonPath('data.updates_feed.0.new_score', 81);
});

it('returns Level-1-only suggestion cards (no dimension scores, description or owner) — دستور I', function () {
    $fintech = invCategory('fintech', 'التقنية المالية');
    $project = Project::factory()->published()->create(['user_id' => $this->owner->id, 'category_id' => $fintech->id, 'ai_score' => 74]);

    $suggestion = $this->getJson('/api/dashboard/investor')->json('data.suggestions.0');

    expect($suggestion)->toMatchArray([
        'id' => $project->id,
        'category' => 'التقنية المالية',
        'status' => 'needs_funding',
        'ai_score' => 74,
        'engagement_badge' => null,
    ]);
    expect($suggestion['title'])->toBeString();
    expect($suggestion)->toHaveKeys([
        'id', 'title', 'category', 'status', 'ai_score', 'budget_min', 'budget_max', 'cover_image_url', 'engagement_badge',
    ]);
    expect($suggestion)->not->toHaveKeys(['dimensions', 'description', 'owner', 'gap_analysis']);
});

it('caps suggestions at 10 and sorts sector match first (SRS-F11-01)', function () {
    $fintech = invCategory('fintech', 'التقنية المالية');
    $health = invCategory('healthtech', 'التقنية الصحية');

    foreach (range(1, 12) as $i) {
        Project::factory()->published()->create(['user_id' => $this->owner->id, 'category_id' => $fintech->id, 'ai_score' => $i]);
    }
    Project::factory()->published()->create(['user_id' => $this->owner->id, 'category_id' => $health->id, 'ai_score' => 99]);

    $suggestions = $this->getJson('/api/dashboard/investor')->json('data.suggestions');

    expect($suggestions)->toHaveCount(10);
    // مطابقة القطاع (fintech) تسبق حتى الأعلى درجة من غير المطابق.
    $categories = collect($suggestions)->pluck('category')->values()->all();
    expect($categories[0])->toBe('التقنية المالية');
});

it('marks profile_complete false when required investor fields are missing (US-056/4)', function () {
    Sanctum::actingAs(User::factory()->investor()->create([
        'investment_focus' => null,
        'preferred_sectors' => null,
    ]));

    $this->getJson('/api/dashboard/investor')
        ->assertOk()
        ->assertJsonPath('data.profile_complete', false);
});

it('shapes sent_interests: can_cancel only when pending · agreement when accepted with PDF', function () {
    $fintech = invCategory('fintech', 'التقنية المالية');
    $pendingProject = Project::factory()->published()->create(['user_id' => $this->owner->id, 'category_id' => $fintech->id]);
    $acceptedProject = Project::factory()->published()->create(['user_id' => $this->owner->id, 'category_id' => $fintech->id]);
    $rejectedProject = Project::factory()->published()->create(['user_id' => $this->owner->id, 'category_id' => $fintech->id]);

    $this->investor->interestsSent()->create(['project_id' => $pendingProject->id, 'interest_type' => 'investment', 'status' => InterestStatus::PENDING]);

    // طلب مقبول + سجل اتفاق حقيقي (يمنع انتهاك FK agreement_id).
    $accepted = $this->investor->interestsSent()->create([
        'project_id' => $acceptedProject->id,
        'interest_type' => 'consultation',
        'status' => InterestStatus::ACCEPTED,
        'agreement_pdf_path' => 'agreements/123.pdf',
    ]);
    $agreement = Agreement::create([
        'interest_id' => $accepted->id,
        'idea_owner_id' => $this->owner->id,
        'investor_id' => $this->investor->id,
        'project_id' => $acceptedProject->id,
        'pdf_path' => 'agreements/123.pdf',
        'idea_owner_name' => $this->owner->name,
        'investor_name' => $this->investor->name,
    ]);
    $accepted->forceFill(['agreement_id' => $agreement->id])->save();

    $this->investor->interestsSent()->create([
        'project_id' => $rejectedProject->id,
        'interest_type' => 'investment',
        'status' => InterestStatus::REJECTED,
        'rejection_reason' => 'نبحث عن مشاريع مبكرة',
    ]);

    $sent = $this->getJson('/api/dashboard/investor')->json('data.sent_interests');

    expect($sent)->toHaveCount(3);

    $byProject = collect($sent)->keyBy('project.id');

    expect($byProject[$pendingProject->id])->toMatchArray([
        'status' => 'pending',
        'can_cancel' => true,
        'agreement_available' => false,
        'agreement_url' => null,
    ]);
    expect($byProject[$acceptedProject->id])->toMatchArray([
        'status' => 'accepted',
        'can_cancel' => false,
        'agreement_available' => true,
        'agreement_url' => '/api/agreements/'.$agreement->id,
    ]);
    expect($byProject[$rejectedProject->id])->toMatchArray([
        'status' => 'rejected',
        'can_cancel' => false,
        'rejection_reason' => 'نبحث عن مشاريع مبكرة',
    ]);
});

it('excludes cancelled interests from the sent_requests KPI', function () {
    $project = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $this->investor->interestsSent()->create(['project_id' => $project->id, 'interest_type' => 'investment', 'status' => InterestStatus::PENDING]);
    $this->investor->interestsSent()->create(['project_id' => $project->id, 'interest_type' => 'investment', 'status' => InterestStatus::CANCELLED]);

    $this->getJson('/api/dashboard/investor')
        ->assertJsonPath('data.kpis.sent_requests', 1);
});

it('flags a soft-deleted saved project as unavailable but keeps it listed (US-059/6)', function () {
    $project = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $this->investor->savedProjects()->create(['project_id' => $project->id]);

    $project->delete();

    $this->getJson('/api/dashboard/investor')
        ->assertOk()
        ->assertJsonCount(1, 'data.saved_projects')
        ->assertJsonPath('data.saved_projects.0.project.available', false);
});

it('excludes the investor\'s own projects from suggestions (US-056/1)', function () {
    $fintech = invCategory('fintech', 'التقنية المالية');
    Project::factory()->published()->create(['user_id' => $this->investor->id, 'category_id' => $fintech->id, 'ai_score' => 99]);

    $suggestions = $this->getJson('/api/dashboard/investor')->json('data.suggestions');

    expect($suggestions)->toBeEmpty();
});

it('blocks an idea owner from the investor dashboard with 403 (ERR-403-02)', function () {
    Sanctum::actingAs(User::factory()->ideaOwner()->create());

    $this->getJson('/api/dashboard/investor')
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('requires authentication', function () {
    auth()->forgetGuards();

    $this->getJson('/api/dashboard/investor')->assertStatus(401);
});
