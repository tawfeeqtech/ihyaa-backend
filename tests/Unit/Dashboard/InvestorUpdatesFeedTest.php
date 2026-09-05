<?php

namespace Tests\Unit\Dashboard;

use App\Enums\EvaluationStatus;
use App\Enums\InterestStatus;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use App\Services\Dashboard\InvestorUpdatesFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * InvestorUpdatesFeedService — T098 · US-060 (dashboard-api.md §2.updates_feed · data-model §5.2).
 *
 * اشتقاق عند الطلب (لا جدول أحداث): engagement set = interests غير الملغاة UNION saved ·
 * evaluation_updated عند اختلاف الدرجة عن التقييم المكتمل السابق · project_edited عند
 * updated_at > COALESCE(last_evaluation_at, created_at) · دمج تنازلي وقطع عند 20.
 */

/** ينشئ تقييماً بتاريخ قطعي على مشروع. */
function feedEval(Project $project, float $score, string $createdAt, int $version = 1): Evaluation
{
    $evaluation = Evaluation::create([
        'project_id' => $project->id,
        'version' => $version,
        'status' => EvaluationStatus::COMPLETED->value,
        'overall_score' => $score,
        'completed_at' => Carbon::parse($createdAt),
    ]);

    $evaluation->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

    return $evaluation;
}

beforeEach(function () {
    config(['scout.driver' => 'null']);
    $this->feed = app(InvestorUpdatesFeedService::class);
    $this->investor = User::factory()->investor()->create();
    $this->owner = User::factory()->ideaOwner()->create();
});

it('emits an evaluation_updated event only when the score changes (data-model §5.2)', function () {
    $project = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $this->investor->savedProjects()->create(['project_id' => $project->id]);

    feedEval($project, 62, '2026-08-01 10:00:00', 1);   // لا حدث — لا سابقة
    $updated = feedEval($project, 81, '2026-08-02 10:00:00', 2); // 62 ≠ 81 → حدث

    $events = $this->feed->for($this->investor)->all();

    expect($events)->toHaveCount(1);
    expect($events[0]['id'])->toBe('ev-'.$updated->id);
    expect($events[0]['type'])->toBe('evaluation_updated');
    expect($events[0]['project'])->toBe(['id' => $project->id, 'title' => $project->title]);
    expect($events[0]['old_score'])->toBe(62);
    expect($events[0]['new_score'])->toBe(81);
    expect($events[0]['created_at'])->toBe($updated->created_at->toISOString());
});

it('does not emit an event when the score is unchanged', function () {
    $project = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $this->investor->savedProjects()->create(['project_id' => $project->id]);

    feedEval($project, 70, '2026-08-01 10:00:00', 1);
    feedEval($project, 70, '2026-08-02 10:00:00', 2);

    expect($this->feed->for($this->investor))->toBeEmpty();
});

it('emits a project_edited event when updated_at is newer than the anchor', function () {
    $project = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $this->investor->savedProjects()->create(['project_id' => $project->id]);

    // لا تقييم → anchor = created_at؛ نحدّث المشروع بعد ساعة.
    $project->forceFill(['updated_at' => $project->created_at->copy()->addHour()])->save();

    $events = $this->feed->for($this->investor)->all();

    expect($events)->toHaveCount(1);
    expect($events[0]['id'])->toBe('pr-'.$project->id);
    expect($events[0]['type'])->toBe('project_edited');
    expect($events[0]['old_score'])->toBeNull();
    expect($events[0]['new_score'])->toBeNull();
});

it('uses the last evaluation as the edit anchor when one exists', function () {
    $project = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $this->investor->savedProjects()->create(['project_id' => $project->id]);

    // آخر تقييم عند 10:00 — أي تعديل قبله لا يصدر حدثاً.
    $project->forceFill(['last_evaluation_at' => Carbon::parse('2026-08-02 10:00:00')])->save();
    $project->forceFill(['updated_at' => Carbon::parse('2026-08-01 12:00:00')])->save();

    expect($this->feed->for($this->investor))->toBeEmpty();

    // تعديل بعد التقييم → حدث.
    $project->forceFill(['updated_at' => Carbon::parse('2026-08-03 09:00:00')])->save();

    $events = $this->feed->for($this->investor)->all();

    expect($events)->toHaveCount(1);
    expect($events[0]['type'])->toBe('project_edited');
});

it('scopes the feed to engaged projects only — cancelled interests drop out (US-060/5)', function () {
    $cancelled = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $kept = Project::factory()->published()->create(['user_id' => $this->owner->id]);

    // طلب أُلغي → يخرج من النطاق.
    $interest = $this->investor->interestsSent()->create([
        'project_id' => $cancelled->id,
        'interest_type' => 'investment',
        'status' => InterestStatus::CANCELLED,
    ]);
    unset($interest);

    // محفوظ → داخل النطاق.
    $this->investor->savedProjects()->create(['project_id' => $kept->id]);

    feedEval($cancelled, 50, '2026-08-01 10:00:00', 1);
    feedEval($cancelled, 80, '2026-08-02 10:00:00', 2);
    feedEval($kept, 90, '2026-08-01 10:00:00', 1);
    feedEval($kept, 95, '2026-08-02 10:00:00', 2);

    $projectIds = collect($this->feed->for($this->investor))->pluck('project.id')->unique()->values()->all();

    expect($projectIds)->toBe([$kept->id]);
});

it('merges both event types descending and caps at 20 (SRS-F11-05)', function () {
    $projects = [];
    foreach (range(1, 22) as $i) {
        $project = Project::factory()->published()->create(['user_id' => $this->owner->id]);
        $this->investor->savedProjects()->create(['project_id' => $project->id]);
        // أحداث متباعدة زمنياً بعد الإنشاء — الأحدث (i=22) آخرها.
        $project->forceFill(['updated_at' => now()->addMinutes($i)])->save();
        $projects[] = $project;
    }

    $events = $this->feed->for($this->investor)->all();

    expect($events)->toHaveCount(20);

    // الأحدث أولاً — المشروع الأخير (i=22) في المقدمة.
    expect($events[0]['project']['id'])->toBe($projects[21]->id);
    // ترتيب زمني تنازلي قطعي.
    $timestamps = collect($events)->pluck('created_at')->values()->all();
    $sorted = collect($timestamps)->sortDesc()->values()->all();
    expect($timestamps)->toBe($sorted);
});

it('returns an empty feed when the investor has no engagement', function () {
    Project::factory()->published()->create(['user_id' => $this->owner->id]);

    expect($this->feed->for($this->investor))->toBeEmpty();
});
