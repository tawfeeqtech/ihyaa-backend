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

/** بيانات صالحة لإنشاء مشروع لاختبار رابط الفيديو — US-011. */
function videoUrlPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'مشروع اختبار رابط الفيديو',
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

// ——————————————————————— US-011: رابط الفيديو (SRS-F02-03) ———————————————————————

it('accepts a youtube.com URL and infers the youtube provider', function () {
    $this->postJson('/api/projects', videoUrlPayload([
        'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ]))
        ->assertStatus(201)
        ->assertJsonPath('data.video.provider', 'youtube');
});

it('accepts a youtu.be short URL and infers the youtube provider', function () {
    $this->postJson('/api/projects', videoUrlPayload([
        'video_url' => 'https://youtu.be/dQw4w9WgXcQ',
    ]))
        ->assertStatus(201)
        ->assertJsonPath('data.video.provider', 'youtube');
});

it('accepts a vimeo.com URL and infers the vimeo provider', function () {
    $this->postJson('/api/projects', videoUrlPayload([
        'video_url' => 'https://vimeo.com/123456789',
    ]))
        ->assertStatus(201)
        ->assertJsonPath('data.video.provider', 'vimeo');
});

it('rejects a direct mp4 file URL', function () {
    $this->postJson('/api/projects', videoUrlPayload([
        'video_url' => 'https://example.com/video.mp4',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('video_url');
});

it('rejects URLs from unapproved providers', function () {
    $this->postJson('/api/projects', videoUrlPayload([
        'video_url' => 'https://dailymotion.com/video/x8abc',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('video_url');
});

it('rejects a scheme-less video URL', function () {
    $this->postJson('/api/projects', videoUrlPayload([
        'video_url' => 'youtube.com/watch?v=abc123',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('video_url');
});
