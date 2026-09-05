<?php

namespace Tests\Feature\Project;

use App\Enums\ProjectState;
use App\Enums\ProjectStatus;
use App\Enums\VisibilityLevel;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** بيانات صالحة لإنشاء مشروع — تُعدَّل حسب الحالة (T133/T134). */
function validProjectPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'مشروع اختبار صالح للتحقق',
        'description' => 'وصف المشروع التفصيلي للاختبار — وصف المشروع التفصيلي للاختبار — وصف المشروع التفصيلي للاختبار.',
        'category_id' => Category::factory()->create()->id,
        'status' => ProjectState::NEEDS_FUNDING->value,
        'publication_status' => ProjectStatus::PUBLISHED->value,
        'visibility_level' => VisibilityLevel::REGISTERED->value,
    ], $overrides);
}

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($this->owner);
});

// ——————————————————————— T133: استنتاج video_provider ———————————————————————

it('infers youtube video_provider from a youtube.com URL (T133)', function () {
    $this->postJson('/api/projects', validProjectPayload([
        'video_url' => 'https://www.youtube.com/watch?v=abc123',
    ]))
        ->assertStatus(201)
        ->assertJsonPath('data.video.provider', 'youtube');
});

it('infers youtube video_provider from a youtu.be URL (T133)', function () {
    $this->postJson('/api/projects', validProjectPayload([
        'video_url' => 'https://youtu.be/abc123',
    ]))
        ->assertStatus(201)
        ->assertJsonPath('data.video.provider', 'youtube');
});

it('infers vimeo video_provider from a vimeo.com URL (T133)', function () {
    $this->postJson('/api/projects', validProjectPayload([
        'video_url' => 'https://vimeo.com/123456789',
    ]))
        ->assertStatus(201)
        ->assertJsonPath('data.video.provider', 'vimeo');
});

it('keeps an explicitly provided video_provider when it matches (T133)', function () {
    $this->postJson('/api/projects', validProjectPayload([
        'video_url' => 'https://www.youtube.com/watch?v=abc123',
        'video_provider' => 'youtube',
    ]))
        ->assertStatus(201)
        ->assertJsonPath('data.video.provider', 'youtube');
});

it('leaves video_provider null when no video_url is provided (T133)', function () {
    $this->postJson('/api/projects', validProjectPayload())
        ->assertStatus(201)
        ->assertJsonPath('data.video', null);
});

it('infers video_provider on update when only video_url changes (T133)', function () {
    $project = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
    ]);

    $this->putJson("/api/projects/{$project->id}", validProjectPayload([
        'video_url' => 'https://vimeo.com/999999',
    ]))
        ->assertStatus(200)
        ->assertJsonPath('data.project.video.provider', 'vimeo');
});

it('preserves the stored video_provider on a partial update without video fields (T133)', function () {
    $project = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'video_url' => 'https://www.youtube.com/watch?v=old123',
        'video_provider' => 'youtube',
    ]);

    $this->putJson("/api/projects/{$project->id}", validProjectPayload([
        'title' => 'تحديث عنوان فقط دون لمس الفيديو',
    ]))
        ->assertStatus(200)
        ->assertJsonPath('data.project.video.provider', 'youtube');
});

// ——————————————————————— T134: تقييد github_url ———————————————————————

it('accepts a github.com github_url (T134)', function () {
    $this->postJson('/api/projects', validProjectPayload([
        'github_url' => 'https://github.com/tawfeeqtech/Ihyaa',
    ]))
        ->assertStatus(201)
        ->assertJsonPath('data.github_url', 'https://github.com/tawfeeqtech/Ihyaa');
});

it('accepts a www.github.com github_url (T134)', function () {
    $this->postJson('/api/projects', validProjectPayload([
        'github_url' => 'https://www.github.com/tawfeeqtech/Ihyaa',
    ]))
        ->assertStatus(201);
});

it('rejects a non-github github_url (T134)', function () {
    $this->postJson('/api/projects', validProjectPayload([
        'github_url' => 'https://gitlab.com/some/repo',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('github_url');
});

it('rejects a scheme-less github_url (T134)', function () {
    $this->postJson('/api/projects', validProjectPayload([
        'github_url' => 'github.com/tawfeeqtech/Ihyaa',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('github_url');
});
