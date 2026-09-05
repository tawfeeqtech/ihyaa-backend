<?php

namespace Tests\Feature\Api\Dashboard;

use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * GET /api/dashboard/idea-owner — T049 · US-051/052/053 (dashboard-api.md §1).
 *
 * الدور: idea-owner فقط (403 ERR-403-02 للمستثمر) · البنية: { kpis, projects, feed }.
 * الدستور II: كل بطاقة تحمل حالة AI (completed/processing/failed/null).
 * المهملات لا تظهر في البطاقات ولا تُحتسب في KPIs.
 */

beforeEach(function () {
    config(['scout.driver' => 'null']);
    $this->owner = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($this->owner);
});

it('returns kpis, project mini-cards and feed for the idea owner', function () {
    $p1 = Project::factory()->published()->create(['user_id' => $this->owner->id, 'ai_score' => 80]);
    Project::factory()->published()->create(['user_id' => $this->owner->id, 'ai_score' => null]);

    Evaluation::create([
        'project_id' => $p1->id,
        'version' => 1,
        'status' => EvaluationStatus::COMPLETED->value,
        'overall_score' => 80,
        'completed_at' => now(),
    ]);

    Notification::create([
        'user_id' => $this->owner->id,
        'type' => 'interest_new',
        'title' => 'اهتمام جديد',
        'body' => 'أبدى مستثمر اهتماماً بمشروعك',
        'data' => ['project_id' => $p1->id, 'url' => '/projects/'.$p1->id],
    ]);

    $response = $this->getJson('/api/dashboard/idea-owner');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.kpis.total_projects', 2)
        ->assertJsonPath('data.kpis.average_score', 80) // JSON يرمّز 80.0 كـ 80
        ->assertJsonPath('data.kpis.average_score_note', 'average_score_excludes_incomplete')
        ->assertJsonCount(2, 'data.projects')
        ->assertJsonPath('data.feed.items.0.type', 'interest_received')
        ->assertJsonPath('data.feed.items.0.related_project.id', $p1->id);

    // كلتا الحالتين (مقيَّم / غير مقيَّم) موجودتان في البطاقات.
    $states = collect($response->json('data.projects'))->pluck('evaluation_status')->sort()->values()->all();
    expect($states)->toBe([null, 'completed']);
});

it('never includes trashed projects in the mini-card list or the KPIs', function () {
    Project::factory()->published()->create(['user_id' => $this->owner->id]);
    Project::factory()->trashed()->create(['user_id' => $this->owner->id]);

    $this->getJson('/api/dashboard/idea-owner')
        ->assertOk()
        ->assertJsonPath('data.kpis.total_projects', 1)
        ->assertJsonCount(1, 'data.projects');
});

it('reports the four AI evaluation states on the mini-cards', function () {
    $completed = Project::factory()->published()->create(['user_id' => $this->owner->id, 'ai_score' => 90]);
    $processing = Project::factory()->published()->create(['user_id' => $this->owner->id, 'ai_score' => null]);
    $failed = Project::factory()->published()->create(['user_id' => $this->owner->id, 'ai_score' => null]);
    Project::factory()->published()->create(['user_id' => $this->owner->id, 'ai_score' => null]);

    Evaluation::create(['project_id' => $completed->id, 'version' => 1, 'status' => EvaluationStatus::COMPLETED->value, 'overall_score' => 90, 'completed_at' => now()]);
    Evaluation::create(['project_id' => $processing->id, 'version' => 1, 'status' => EvaluationStatus::PROCESSING->value, 'completed_at' => now()]);
    Evaluation::create(['project_id' => $failed->id, 'version' => 1, 'status' => EvaluationStatus::FAILED->value, 'completed_at' => now()]);

    $states = collect($this->getJson('/api/dashboard/idea-owner')->json('data.projects'))
        ->pluck('evaluation_status')->sort()->values()->all();

    expect($states)->toBe([null, 'completed', 'failed', 'processing']);
});

it('orders the latest updated project first in the cards', function () {
    $older = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $newer = Project::factory()->published()->create(['user_id' => $this->owner->id]);

    // ترتيب قطعي: أجِّل updated_at للمشروع الأول إلى قبل ساعة.
    $older->updated_at = now()->subHour();
    $older->save();

    $ids = collect($this->getJson('/api/dashboard/idea-owner')->json('data.projects'))
        ->pluck('id')->values()->all();

    expect($ids[0])->toBe($newer->id);
    expect($ids[1])->toBe($older->id);
});

it('blocks an investor from the idea-owner dashboard with 403 (ERR-403-02)', function () {
    Sanctum::actingAs(User::factory()->investor()->create());

    $this->getJson('/api/dashboard/idea-owner')
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('requires authentication', function () {
    auth()->forgetGuards(); // يلغي Sanctum::actingAs من beforeEach

    $this->getJson('/api/dashboard/idea-owner')->assertStatus(401);
});
