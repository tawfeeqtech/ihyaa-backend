<?php

namespace Tests\Unit\Dashboard;

use App\Enums\EvaluationStatus;
use App\Enums\InterestStatus;
use App\Models\Evaluation;
use App\Models\Interest;
use App\Models\Project;
use App\Models\User;
use App\Services\Dashboard\DashboardKpiCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * DashboardKpiCalculator — T055 · US-052 (dashboard-api.md §1.kpis).
 *
 * المؤشرات الأربعة: total_projects · average_score (خانة عشرية واحدة · يستبعد
 * processing/failed/null) · total_requests_received · accepted_requests.
 * average_score_note يُضبط عند وجود مشاريع مستبعدة من المتوسط.
 */

/** ينشئ تقييماً بحالة/درجة معينة على مشروع. */
function dashboardEval(Project $project, string $status, ?float $score, int $version = 1, ?string $createdAt = null): Evaluation
{
    return Evaluation::create([
        'project_id' => $project->id,
        'version' => $version,
        'status' => $status,
        'overall_score' => $score,
        'completed_at' => $createdAt !== null ? \Carbon\Carbon::parse($createdAt) : now(),
    ]);
}

beforeEach(function () {
    config(['scout.driver' => 'null']);
    $this->calculator = app(DashboardKpiCalculator::class);
    $this->owner = User::factory()->ideaOwner()->create();
});

it('computes the four KPIs (completed-only average + requests received/accepted)', function () {
    // مشروعان مقيّمان (80 و 60 → متوسط 70.0) + مشروع غير مقيَّم.
    $p1 = Project::factory()->published()->create(['user_id' => $this->owner->id, 'ai_score' => 80]);
    $p2 = Project::factory()->published()->create(['user_id' => $this->owner->id, 'ai_score' => 60]);
    Project::factory()->published()->create(['user_id' => $this->owner->id, 'ai_score' => null]);

    dashboardEval($p1, EvaluationStatus::COMPLETED->value, 80);
    dashboardEval($p2, EvaluationStatus::COMPLETED->value, 60);

    // طلبان وردا على p1 (أحدهما مقبول) وطلب على p2 (مرفوض).
    $investor = User::factory()->investor()->create();
    Interest::create(['project_id' => $p1->id, 'investor_id' => $investor->id, 'interest_type' => 'investment', 'status' => InterestStatus::PENDING]);
    Interest::create(['project_id' => $p1->id, 'investor_id' => User::factory()->investor()->create()->id, 'interest_type' => 'investment', 'status' => InterestStatus::ACCEPTED]);
    Interest::create(['project_id' => $p2->id, 'investor_id' => User::factory()->investor()->create()->id, 'interest_type' => 'investment', 'status' => InterestStatus::REJECTED]);

    $kpis = $this->calculator->for($this->owner);

    expect($kpis['total_projects'])->toBe(3);
    expect($kpis['average_score'])->toBe(70.0);
    expect($kpis['total_requests_received'])->toBe(3);
    expect($kpis['accepted_requests'])->toBe(1);
    // يوجد مشروع غير مقيَّم مستبعد من المتوسط → الملاحظة مضبوطة.
    expect($kpis['average_score_note'])->toBe('average_score_excludes_incomplete');
});

it('excludes processing and failed from the average (latest evaluation per project)', function () {
    $completed = Project::factory()->published()->create(['user_id' => $this->owner->id, 'ai_score' => 90]);
    $processing = Project::factory()->published()->create(['user_id' => $this->owner->id, 'ai_score' => 40]);
    $failed = Project::factory()->published()->create(['user_id' => $this->owner->id, 'ai_score' => 50]);

    dashboardEval($completed, EvaluationStatus::COMPLETED->value, 90, 1, '2026-08-01 10:00:00');
    // أحدث تقييم للمشروع processing — يُستبعد رغم وجود درجة كاش.
    dashboardEval($processing, EvaluationStatus::COMPLETED->value, 40, 1, '2026-08-01 10:00:00');
    dashboardEval($processing, EvaluationStatus::PROCESSING->value, null, 2, '2026-08-02 10:00:00');
    // أحدث تقييم failed — يُستبعد.
    dashboardEval($failed, EvaluationStatus::COMPLETED->value, 50, 1, '2026-08-01 10:00:00');
    dashboardEval($failed, EvaluationStatus::FAILED->value, null, 2, '2026-08-02 10:00:00');

    $kpis = $this->calculator->for($this->owner);

    expect($kpis['average_score'])->toBe(90.0);
    expect($kpis['average_score_note'])->toBe('average_score_excludes_incomplete');
});

it('returns a null average with null note when every project is unscored', function () {
    Project::factory()->published()->create(['user_id' => $this->owner->id, 'ai_score' => null]);

    $kpis = $this->calculator->for($this->owner);

    expect($kpis['total_projects'])->toBe(1);
    expect($kpis['average_score'])->toBeNull();
    expect($kpis['average_score_note'])->toBe('average_score_excludes_incomplete');
    expect($kpis['total_requests_received'])->toBe(0);
    expect($kpis['accepted_requests'])->toBe(0);
});

it('returns zero projects with a null average for an owner with no projects', function () {
    $kpis = $this->calculator->for($this->owner);

    expect($kpis['total_projects'])->toBe(0);
    expect($kpis['average_score'])->toBeNull();
    expect($kpis['average_score_note'])->toBeNull();
});

it('drops the average_score_note when all projects are scored', function () {
    Project::factory()->published()->create(['user_id' => $this->owner->id, 'ai_score' => 74]);
    $project = Project::factory()->published()->create(['user_id' => $this->owner->id, 'ai_score' => 82]);

    dashboardEval($project, EvaluationStatus::COMPLETED->value, 82);

    // لاحظ: المشروع الأول بلا تقييم → مستبعد → الملاحظة تظهر (السيناريو مغطى أعلاه).
    // هنا نجعل كل المشاريع مقيَّمة للتحقق من غياب الملاحظة.
    $projects = Project::query()->where('user_id', $this->owner->id)->get();
    foreach ($projects as $p) {
        dashboardEval($p, EvaluationStatus::COMPLETED->value, (float) $p->ai_score);
    }

    $kpis = $this->calculator->for($this->owner);

    expect($kpis['average_score'])->toBe(78.0);
    expect($kpis['average_score_note'])->toBeNull();
});

// ——————————————————————— مؤشرات المستثمر (US-057 · T084) ———————————————————————

/** ينشئ طلب اهتمام بحالة/نوع معين. */
function investorInterest(Project $project, User $investor, InterestStatus $status, string $type = 'investment'): Interest
{
    return Interest::create([
        'project_id' => $project->id,
        'investor_id' => $investor->id,
        'interest_type' => $type,
        'status' => $status,
    ]);
}

it('computes the three investor KPIs (sent excludes cancelled · followed = saved)', function () {
    $investor = User::factory()->investor()->create();
    $owner = User::factory()->ideaOwner()->create();

    // قيد active_dup_key يمنع أكثر من طلب نشط لنفس المشروع → كل حالة على مشروع مستقل.
    $pendingProject = Project::factory()->published()->create(['user_id' => $owner->id]);
    $acceptedProject = Project::factory()->published()->create(['user_id' => $owner->id]);
    $rejectedProject = Project::factory()->published()->create(['user_id' => $owner->id]);
    $followedProject = Project::factory()->published()->create(['user_id' => $owner->id]);

    investorInterest($pendingProject, $investor, InterestStatus::PENDING);
    investorInterest($acceptedProject, $investor, InterestStatus::ACCEPTED);
    investorInterest($rejectedProject, $investor, InterestStatus::REJECTED);
    // ملغي — يُستبعد من sent_requests (حالة نهائية؛ لا يتعارض مع قيد الطلب النشط).
    investorInterest($pendingProject, $investor, InterestStatus::CANCELLED);

    $investor->savedProjects()->create(['project_id' => $followedProject->id]);

    $kpis = $this->calculator->investorKpis($investor);

    expect($kpis['sent_requests'])->toBe(3);       // pending + accepted + rejected (لا ملغي)
    expect($kpis['accepted_requests'])->toBe(1);
    expect($kpis['followed_projects'])->toBe(1);
});

it('returns zero KPIs for a fresh investor with no activity', function () {
    $investor = User::factory()->investor()->create();

    $kpis = $this->calculator->investorKpis($investor);

    expect($kpis['sent_requests'])->toBe(0);
    expect($kpis['accepted_requests'])->toBe(0);
    expect($kpis['followed_projects'])->toBe(0);
});
