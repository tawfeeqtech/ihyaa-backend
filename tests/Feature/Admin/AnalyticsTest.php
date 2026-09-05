<?php

namespace Tests\Feature\Admin;

use App\Enums\InterestStatus;
use App\Enums\InterestType;
use App\Enums\ProjectState;
use App\Models\Category;
use App\Models\Interest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('scout.driver', 'null');
});

/** إنشاء طلب اهتمام مباشرة (بلا تحقق من الـ Controller — الاختبار يفحص التجميع فقط). */
function makeInterest(Project $project, User $investor, InterestStatus $status): Interest
{
    return Interest::create([
        'project_id' => $project->id,
        'investor_id' => $investor->id,
        'interest_type' => InterestType::INVESTMENT,
        'status' => $status,
    ]);
}

test('admin analytics returns numbers matching the database 100% (T083 · US-061)', function () {
    // — مستخدمون —
    $owner1 = User::factory()->ideaOwner()->create();
    $owner2 = User::factory()->ideaOwner()->create();
    $investor = User::factory()->investor()->create();
    $admin = User::factory()->admin()->create();

    // — مجالات ومشاريع —
    $fintech = Category::factory()->create(['name_ar' => 'التقنية المالية', 'slug' => 'fintech']);
    $health = Category::factory()->create(['name_ar' => 'التقنية الصحية', 'slug' => 'healthtech']);

    $p1 = Project::factory()->create([
        'user_id' => $owner1->id, 'category_id' => $fintech->id,
        'status' => ProjectState::NEEDS_FUNDING, 'ai_score' => 80,
    ]);
    $p2 = Project::factory()->create([
        'user_id' => $owner2->id, 'category_id' => $health->id,
        'status' => ProjectState::COMPLETED, 'ai_score' => 60,
    ]);
    $p3 = Project::factory()->create([
        'user_id' => $owner2->id, 'category_id' => $fintech->id,
        'status' => ProjectState::NEEDS_DEVELOPMENT, 'ai_score' => null,
    ]);
    $trashed = Project::factory()->create([
        'user_id' => $owner1->id, 'category_id' => $health->id,
        'status' => ProjectState::NEEDS_FUNDING, 'ai_score' => 50,
    ]);
    $trashed->delete(); // SoftDeletes — يجب ألا يظهر في active ولا في sector

    // — طلبات بكل الحالات (مستثمرون مختلفون لتجنب تعارض active_dup_key) —
    $investor2 = User::factory()->investor()->create();
    $investor3 = User::factory()->investor()->create();

    makeInterest($p1, $investor, InterestStatus::PENDING);
    makeInterest($p2, $investor, InterestStatus::ACCEPTED);
    makeInterest($p3, $investor, InterestStatus::ACCEPTED_PENDING_DOCUMENT);
    makeInterest($p1, $investor2, InterestStatus::REJECTED);
    makeInterest($p2, $investor3, InterestStatus::CANCELLED);

    Sanctum::actingAs($admin, ['*']);

    $response = $this->getJson('/api/admin/analytics')->assertOk();

    $data = $response->json('data');

    // generated_at
    expect($data)->toHaveKey('generated_at');
    expect($data['generated_at'])->toBeString();

    // — المستخدمون —
    expect($data['users']['total'])->toBe(6);
    expect($data['users']['by_role']['idea_owner'])->toBe(2);
    expect($data['users']['by_role']['investor'])->toBe(3);
    expect($data['users']['by_role']['admin'])->toBe(1);

    // — المشاريع —
    expect($data['projects']['total'])->toBe(4);   // 3 نشطة + 1 محذوف
    expect($data['projects']['active'])->toBe(3);
    expect($data['projects']['trashed'])->toBe(1);
    expect($data['projects']['by_project_status']['needs_funding'])->toBe(1);
    expect($data['projects']['by_project_status']['completed'])->toBe(1);
    expect($data['projects']['by_project_status']['needs_development'])->toBe(1);

    // — متوسط التقييم: (80+60)/2 = 70 — (ai_score null والمحذوف خارج المتوسط)
    // json يُسقط الكسر الصفري (70.0 ← 70) — مقارنة رقمية بعد تحويل float
    expect((float) $data['avg_ai_score'])->toBe(70.0);

    // — توزيع المجالات (المشاريع النشطة فقط — المحذوف مستبعد) —
    $sectors = collect($data['sector_distribution']);
    expect($sectors->firstWhere('category', 'التقنية المالية')['count'])->toBe(2);
    expect($sectors->firstWhere('category', 'التقنية الصحية')['count'])->toBe(1);
    expect($sectors->firstWhere('category', 'التقنية المالية')['percentage'])
        ->toBe(round(2 / 3 * 100, 1)); // 66.7
    expect($data['chart_sufficient']['sector'])->toBeTrue();

    // — طلبات الاهتمام —
    expect($data['interests']['total'])->toBe(5);
    expect($data['interests']['pending'])->toBe(1);
    expect($data['interests']['accepted'])->toBe(2); // accepted + accepted_pending_document
    expect($data['interests']['rejected'])->toBe(1);
    expect($data['interests']['cancelled'])->toBe(1);

    // — المستخدمون النشطون: لا أحد لديه last_active_at → 7 صفوف بلا نشاط —
    expect($data['active_users_7d'])->toHaveCount(7);
    expect(array_sum(array_column($data['active_users_7d'], 'count')))->toBe(0);
    expect($data['chart_sufficient']['active_users'])->toBeFalse();
});

test('idea owner and investor are forbidden from analytics (T083)', function (string $state) {
    $user = User::factory()->{$state}()->create();
    Sanctum::actingAs($user, ['*']);

    $this->getJson('/api/admin/analytics')->assertForbidden();
    $this->get('/api/admin/analytics/export')->assertForbidden();
})->with([
    'idea owner' => ['ideaOwner'],
    'investor' => ['investor'],
]);

test('analytics responds under 500ms p95 (T083 · SRS-NFR-05)', function () {
    // مقياس قريب من MVP
    User::factory()->count(20)->create();
    Project::factory()->count(10)->create();

    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin, ['*']);

    $times = [];
    for ($i = 0; $i < 5; $i++) {
        $start = microtime(true);
        $this->getJson('/api/admin/analytics')->assertOk();
        $times[] = (microtime(true) - $start) * 1000; // ms
    }
    sort($times);
    $p95 = $times[(int) floor(0.95 * (count($times) - 1))];

    expect($p95)->toBeLessThan(500);
});

test('chart_sufficient.active_users is false when all 7 days are zero (T092 · US-062)', function () {
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin, ['*']);

    $data = $this->getJson('/api/admin/analytics')->assertOk()->json('data');

    expect($data['active_users_7d'])->toHaveCount(7);
    expect(array_sum(array_column($data['active_users_7d'], 'count')))->toBe(0);
    expect($data['chart_sufficient']['active_users'])->toBeFalse();
});

test('chart_sufficient.active_users is true when a user is active (T092 · US-062)', function () {
    User::factory()->investor()->create(['last_active_at' => now()]);

    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin, ['*']);

    $data = $this->getJson('/api/admin/analytics')->assertOk()->json('data');

    expect($data['chart_sufficient']['active_users'])->toBeTrue();
    expect(collect($data['active_users_7d'])->last()['count'])->toBe(1);
});
