<?php

namespace Tests\Feature\Admin;

use App\Enums\InterestStatus;
use App\Enums\InterestType;
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

test('interests grouped by status match the database incl cancelled (T095 · US-063)', function () {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->ideaOwner()->create();

    $category = Category::factory()->create();
    $projects = Project::factory()->count(5)->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
    ]);

    $investor = User::factory()->investor()->create();

    // طلب لكل حالة — مشروع مختلف لكل حالة (تجنب تعارض active_dup_key للنشطة)
    $statuses = [
        InterestStatus::PENDING,
        InterestStatus::ACCEPTED,
        InterestStatus::ACCEPTED_PENDING_DOCUMENT,
        InterestStatus::REJECTED,
        InterestStatus::CANCELLED,
    ];

    foreach ($statuses as $i => $status) {
        Interest::create([
            'project_id' => $projects[$i]->id,
            'investor_id' => $investor->id,
            'interest_type' => InterestType::INVESTMENT,
            'status' => $status,
        ]);
    }

    Sanctum::actingAs($admin, ['*']);

    $data = $this->getJson('/api/admin/analytics')->assertOk()->json('data');

    expect($data['interests']['total'])->toBe(5);
    expect($data['interests']['pending'])->toBe(1);
    expect($data['interests']['accepted'])->toBe(2); // accepted + accepted_pending_document
    expect($data['interests']['rejected'])->toBe(1);
    expect($data['interests']['cancelled'])->toBe(1);
});

test('interests are zeroed out when there are none (T095 · US-063)', function () {
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin, ['*']);

    $data = $this->getJson('/api/admin/analytics')->assertOk()->json('data');

    expect($data['interests']['total'])->toBe(0);
    expect($data['interests']['pending'])->toBe(0);
    expect($data['interests']['accepted'])->toBe(0);
    expect($data['interests']['rejected'])->toBe(0);
    expect($data['interests']['cancelled'])->toBe(0);
});
