<?php

namespace Tests\Feature\Project;

use App\Enums\ProjectState;
use App\Enums\ProjectStatus;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->category = Category::factory()->create();

    $this->project = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'category_id' => $this->category->id,
        'title' => 'العنوان الأصلي للمشروع',
        'description' => 'الوصف الأصلي قبل التعديل — الوصف الأصلي قبل التعديل — الوصف الأصلي قبل التعديل.',
        'status' => ProjectState::NEEDS_FUNDING,
        'publication_status' => ProjectStatus::PUBLISHED,
        'tags' => ['laravel'],
    ]);

    Sanctum::actingAs($this->owner);
});

it('updates a project and returns the full project payload', function () {
    $this->putJson("/api/projects/{$this->project->id}", [
        'title' => 'عنوان محدّث للمشروع بعد التعديل',
        'description' => 'الوصف الأصلي قبل التعديل — الوصف الأصلي قبل التعديل — الوصف الأصلي قبل التعديل.',
        'category_id' => $this->category->id,
        'status' => ProjectState::NEEDS_FUNDING->value,
        'publication_status' => ProjectStatus::PUBLISHED->value,
        'tags' => ['laravel'],
    ])
        ->assertStatus(200)
        ->assertJsonStructure(['data' => ['project', 'significant_changes']])
        ->assertJsonPath('data.project.title', 'عنوان محدّث للمشروع بعد التعديل');

    expect($this->project->fresh()->title)->toBe('عنوان محدّث للمشروع بعد التعديل');
});

it('flags significant_changes true when a significant field changes', function () {
    $this->putJson("/api/projects/{$this->project->id}", [
        'title' => 'العنوان الأصلي للمشروع',
        'description' => 'وصف جديد كلياً يغيّر جوهر المشروع — وصف جديد كلياً يغيّر جوهر المشروع — وصف جديد كلياً.',
        'category_id' => $this->category->id,
        'status' => ProjectState::NEEDS_FUNDING->value,
        'publication_status' => ProjectStatus::PUBLISHED->value,
        'tags' => ['laravel'],
    ])
        ->assertStatus(200)
        ->assertJsonPath('data.significant_changes', true);
});

it('keeps significant_changes false when only the title changes', function () {
    $this->putJson("/api/projects/{$this->project->id}", [
        'title' => 'عنوان جديد لا يمس الحقول الجوهرية',
        'description' => 'الوصف الأصلي قبل التعديل — الوصف الأصلي قبل التعديل — الوصف الأصلي قبل التعديل.',
        'category_id' => $this->category->id,
        'status' => ProjectState::NEEDS_FUNDING->value,
        'publication_status' => ProjectStatus::PUBLISHED->value,
        'tags' => ['laravel'],
    ])
        ->assertStatus(200)
        ->assertJsonPath('data.significant_changes', false);
});

it('forbids a non-owner from updating the project', function () {
    $other = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($other);

    $this->putJson("/api/projects/{$this->project->id}", [
        'title' => 'محاولة تعديل من غير المالك',
        'description' => 'الوصف الأصلي قبل التعديل — الوصف الأصلي قبل التعديل — الوصف الأصلي قبل التعديل.',
        'category_id' => $this->category->id,
        'status' => ProjectState::NEEDS_FUNDING->value,
        'publication_status' => ProjectStatus::PUBLISHED->value,
    ])
        ->assertStatus(403);
});

it('returns 404 when updating a soft-deleted project', function () {
    $this->project->delete();

    $this->putJson("/api/projects/{$this->project->id}", [
        'title' => 'العنوان الأصلي للمشروع',
        'description' => 'الوصف الأصلي قبل التعديل — الوصف الأصلي قبل التعديل — الوصف الأصلي قبل التعديل.',
        'category_id' => $this->category->id,
        'status' => ProjectState::NEEDS_FUNDING->value,
        'publication_status' => ProjectStatus::PUBLISHED->value,
    ])
        ->assertStatus(404);
});
