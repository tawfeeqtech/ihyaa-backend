<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('scout.driver', 'null');
});

test('active users report returns 7 rows without gaps, zero days drawn as 0 (T091 · US-062)', function () {
    $admin = User::factory()->admin()->create();

    $today = now();
    $days = collect(range(6, 0))->map(fn (int $i) => $today->copy()->subDays($i));

    // اليوم: مستخدمان · قبل يومين: مستخدم · قبل 5 أيام: مستخدم
    User::factory()->count(2)->investor()->create(['last_active_at' => $days[6]]);
    User::factory()->investor()->create(['last_active_at' => $days[4]]);
    User::factory()->investor()->create(['last_active_at' => $days[1]]);

    Sanctum::actingAs($admin, ['*']);

    $rows = $this->getJson('/api/admin/analytics')->assertOk()->json('data.active_users_7d');

    // 7 صفوف دائماً بلا فجوات
    expect($rows)->toHaveCount(7);

    $expectedDates = $days->map(fn (Carbon $d) => $d->toDateString())->all();
    expect(array_column($rows, 'date'))->toBe($expectedDates);

    // الأعداد
    $byDate = collect($rows)->keyBy('date');
    expect($byDate[$today->toDateString()]['count'])->toBe(2);
    expect($byDate[$today->copy()->subDays(2)->toDateString()]['count'])->toBe(1);
    expect($byDate[$today->copy()->subDays(5)->toDateString()]['count'])->toBe(1);

    // يوم بلا نشاط يُرسم بـ 0 (لا فجوة ولا صف محذوف)
    expect($byDate[$today->copy()->subDays(1)->toDateString()]['count'])->toBe(0);
    expect($byDate[$today->copy()->subDays(3)->toDateString()]['count'])->toBe(0);
    expect($byDate[$today->copy()->subDays(4)->toDateString()]['count'])->toBe(0);
    expect($byDate[$today->copy()->subDays(6)->toDateString()]['count'])->toBe(0);
});

test('a user outside the 7-day window is not counted (T091 · US-062)', function () {
    $admin = User::factory()->admin()->create();

    // قبل 8 أيام — خارج النطاق
    User::factory()->investor()->create(['last_active_at' => now()->subDays(8)]);
    User::factory()->investor()->create(['last_active_at' => now()->subDays(3)]);

    Sanctum::actingAs($admin, ['*']);

    $rows = $this->getJson('/api/admin/analytics')->assertOk()->json('data.active_users_7d');

    expect($rows)->toHaveCount(7);
    expect(collect($rows)->sum('count'))->toBe(1);
    expect(collect($rows)->firstWhere('date', now()->subDays(3)->toDateString())['count'])->toBe(1);
    expect(collect($rows)->firstWhere('date', now()->subDays(8)->toDateString()))->toBeNull();
});
