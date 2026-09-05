<?php

namespace Tests\Feature\Project;

use App\Enums\ProjectState;
use App\Enums\ProjectStatus;
use App\Enums\VisibilityLevel;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** بيانات صالحة لإنشاء مشروع — تُعدَّل حسب الحالة (T066/T089). */
function storeProjectPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'مشروع اختبار صالح للتحقق من الإنشاء',
        'description' => 'وصف المشروع التفصيلي للاختبار — وصف المشروع التفصيلي للاختبار — وصف المشروع التفصيلي للاختبار.',
        'category_id' => Category::factory()->create()->id,
        'status' => ProjectState::NEEDS_FUNDING->value,
        'publication_status' => ProjectStatus::PUBLISHED->value,
        'tags' => ['laravel', 'ai'],
        'visibility_level' => VisibilityLevel::REGISTERED->value,
    ], $overrides);
}

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
});

it('creates a project owned by the authenticated idea owner', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/projects', storeProjectPayload())
        ->assertStatus(201)
        ->assertJsonPath('data.title', 'مشروع اختبار صالح للتحقق من الإنشاء')
        ->assertJsonPath('data.report_access', 'full');

    $this->assertDatabaseHas('projects', [
        'user_id' => $this->owner->id,
        'title' => 'مشروع اختبار صالح للتحقق من الإنشاء',
    ]);
});

it('rejects a description shorter than 50 characters', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/projects', storeProjectPayload([
        'description' => str_repeat('أ', 49),
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('description');
});

it('rejects a description longer than 2000 characters', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/projects', storeProjectPayload([
        'description' => str_repeat('أ', 2001),
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('description');
});

it('rejects an invalid status value', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/projects', storeProjectPayload([
        'status' => 'invalid_status',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('rejects a non-existent category', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/projects', storeProjectPayload([
        'category_id' => 999999,
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('category_id');
});

it('rejects a budget range where min exceeds max', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/projects', storeProjectPayload([
        'budget_min' => 2000,
        'budget_max' => 1000,
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('budget_max');
});

it('rejects more than ten tags', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/projects', storeProjectPayload([
        'tags' => array_fill(0, 11, 'tag'),
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('tags');
});

it('forbids an investor from creating a project', function () {
    $investor = User::factory()->investor()->create();
    Sanctum::actingAs($investor);

    $this->postJson('/api/projects', storeProjectPayload())
        ->assertStatus(403);
});

it('returns 401 for an unauthenticated visitor', function () {
    $this->postJson('/api/projects', storeProjectPayload())
        ->assertStatus(401);
});
