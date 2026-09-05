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

/** فكّ CSV بعد إزالة BOM → مصفوفة صفوف (array of arrays). */
function parseCsv(string $content): array
{
    $withoutBom = substr($content, 3); // إزالة \xEF\xBB\xBF

    return array_map(
        'str_getcsv',
        array_filter(explode("\n", trim($withoutBom)))
    );
}

/** البحث عن قيمة صف بسيط (section, metric). */
function csvValue(array $lines, string $section, string $metric): ?string
{
    foreach ($lines as $line) {
        if (($line[0] ?? null) === $section && ($line[1] ?? null) === $metric) {
            return $line[2] ?? null;
        }
    }

    return null;
}

test('csv export starts with BOM and contains all 6 sections with matching values (T098 · US-064)', function () {
    // بيانات متوقعة
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->ideaOwner()->create();
    $investor = User::factory()->investor()->create();

    $fintech = Category::factory()->create(['name_ar' => 'التقنية المالية', 'slug' => 'fintech']);
    $project = Project::factory()->create([
        'user_id' => $owner->id,
        'category_id' => $fintech->id,
        'status' => ProjectState::NEEDS_FUNDING,
        'ai_score' => 80,
    ]);
    Interest::create([
        'project_id' => $project->id,
        'investor_id' => $investor->id,
        'interest_type' => InterestType::INVESTMENT,
        'status' => InterestStatus::PENDING,
    ]);

    Sanctum::actingAs($admin, ['*']);

    $response = $this->get('/api/admin/analytics/export')->assertOk();

    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $response->assertHeader('Content-Disposition', 'attachment; filename=ihyaa-analytics-'.now()->format('Y-m-d').'.csv');

    $content = $response->streamedContent();

    // BOM أول 3 بايتات
    expect(substr($content, 0, 3))->toBe("\xEF\xBB\xBF");

    $lines = parseCsv($content);

    // الترويسة
    expect($lines[0])->toBe(['section', 'metric', 'value']);

    // الأقسام الستة كاملة
    $sections = array_values(array_unique(array_column($lines, 0)));
    expect($sections)->toContain('users');
    expect($sections)->toContain('projects');
    expect($sections)->toContain('evaluation');
    expect($sections)->toContain('sector');
    expect($sections)->toContain('active_users');
    expect($sections)->toContain('interests');

    // قيم مطابقة للوحة
    expect(csvValue($lines, 'users', 'total'))->toBe('3');
    expect(csvValue($lines, 'users', 'admin'))->toBe('1');
    expect(csvValue($lines, 'users', 'idea_owner'))->toBe('1');
    expect(csvValue($lines, 'users', 'investor'))->toBe('1');

    expect(csvValue($lines, 'projects', 'total'))->toBe('1');
    expect(csvValue($lines, 'projects', 'active'))->toBe('1');
    expect(csvValue($lines, 'projects', 'trashed'))->toBe('0');
    expect(csvValue($lines, 'projects', 'needs_funding'))->toBe('1');
    expect(csvValue($lines, 'projects', 'completed'))->toBe('0');
    expect(csvValue($lines, 'projects', 'needs_development'))->toBe('0');

    expect(csvValue($lines, 'evaluation', 'avg_ai_score'))->toBe('80');

    // sector — metric = اسم المجال (عربي)
    expect(csvValue($lines, 'sector', 'التقنية المالية'))->toBe('1');

    // active_users — 7 صفوف، جميعها 0
    $activeRows = array_values(array_filter($lines, fn (array $l) => ($l[0] ?? null) === 'active_users'));
    expect($activeRows)->toHaveCount(7);
    expect(array_column($activeRows, 2))->toBe(array_fill(0, 7, '0'));

    // interests
    expect(csvValue($lines, 'interests', 'total'))->toBe('1');
    expect(csvValue($lines, 'interests', 'pending'))->toBe('1');
    expect(csvValue($lines, 'interests', 'accepted'))->toBe('0');
    expect(csvValue($lines, 'interests', 'rejected'))->toBe('0');
    expect(csvValue($lines, 'interests', 'cancelled'))->toBe('0');
});

test('csv export is forbidden for non-admins (T098 · US-064)', function () {
    $user = User::factory()->investor()->create();
    Sanctum::actingAs($user, ['*']);

    $this->get('/api/admin/analytics/export')->assertForbidden();
});

test('csv export completes in under 10 seconds at MVP scale (T099 · US-064)', function () {
    // مقياس MVP: 200 مستخدم + 50 مشروع (كل مشروع ينشئ مالكاً عبر الـ factory)
    User::factory()->count(200)->create();
    Project::factory()->count(50)->create();

    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin, ['*']);

    $start = microtime(true);
    $this->get('/api/admin/analytics/export')->assertOk();
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeLessThan(10);
});
