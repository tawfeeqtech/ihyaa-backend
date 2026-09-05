<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ——————————————————————— US-008: تحديث الملف الشخصي ———————————————————————

it('updates an idea owner profile fields', function () {
    $owner = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($owner);

    $this->putJson('/api/profile', [
        'name' => 'سارة الأحمدي',
        'bio' => 'سيرة ذاتية مختصرة لصاحبة الفكرة.',
        'university' => 'جامعة الملك سعود',
        'major' => 'علوم الحاسب',
    ])
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'سارة الأحمدي')
        ->assertJsonPath('data.university', 'جامعة الملك سعود')
        ->assertJsonPath('data.major', 'علوم الحاسب')
        ->assertJsonPath('data.bio', 'سيرة ذاتية مختصرة لصاحبة الفكرة.');
});

it('updates an investor profile fields', function () {
    $investor = User::factory()->investor()->create();
    Sanctum::actingAs($investor);

    $this->putJson('/api/profile', [
        'investment_focus' => 'Fintech',
        'investment_range' => ['min' => 100000, 'max' => 500000],
        'preferred_sectors' => ['التقنية المالية', 'الذكاء الاصطناعي'],
    ])
        ->assertStatus(200)
        ->assertJsonPath('data.investment_focus', 'Fintech')
        ->assertJsonPath('data.investment_range.min', 100000)
        ->assertJsonPath('data.investment_range.max', 500000)
        ->assertJsonCount(2, 'data.preferred_sectors');
});

it('rejects an investment range where min exceeds max', function () {
    $investor = User::factory()->investor()->create();
    Sanctum::actingAs($investor);

    $this->putJson('/api/profile', [
        'investment_range' => ['min' => 500000, 'max' => 100000],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('investment_range.max');
});

it('returns 409 when trying to change the role after it is set', function () {
    $owner = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($owner);

    $this->putJson('/api/profile', ['role' => 'investor'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'ROLE_ALREADY_SET');
});

it('allows a pending OAuth user to set their role once', function () {
    $user = User::factory()->oauthWithoutRole()->create();
    Sanctum::actingAs($user);

    $this->putJson('/api/profile', ['role' => 'idea_owner'])
        ->assertStatus(200)
        ->assertJsonPath('data.role', 'idea_owner');
});

it('rejects a null role for a pending user', function () {
    $user = User::factory()->oauthWithoutRole()->create();
    Sanctum::actingAs($user);

    $this->putJson('/api/profile', ['role' => null])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');
});

it('forbids setting the admin role via profile', function () {
    $user = User::factory()->oauthWithoutRole()->create();
    Sanctum::actingAs($user);

    $this->putJson('/api/profile', ['role' => 'admin'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

// ——————————————————————— US-008: رفع الصورة الشخصية ———————————————————————

it('uploads an avatar successfully', function () {
    Storage::fake('public');
    $user = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/profile/avatar', [
        'avatar' => UploadedFile::fake()->image('avatar.jpg', 100, 100),
    ])
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    expect($response->json('data.avatar_url'))->toContain('/storage/avatars/');
    expect($user->fresh()->avatar_path)->not->toBeNull();

    Storage::disk('public')->assertExists($user->fresh()->avatar_path);
});

it('rejects an avatar that is not an image', function () {
    Storage::fake('public');
    $user = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/profile/avatar', [
        'avatar' => UploadedFile::fake()->create('avatar.txt', 100),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('avatar');
});

it('rejects an avatar larger than 2MB', function () {
    Storage::fake('public');
    $user = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/profile/avatar', [
        'avatar' => UploadedFile::fake()->image('big-avatar.jpg', 100, 100)->size(3000),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('avatar');
});

// ——————————————————————— US-008: الملف العام (L2) ———————————————————————

it('returns a public profile without the email for an idea owner', function () {
    $owner = User::factory()->ideaOwner()->create([
        'bio' => 'نبذة عامة',
        'university' => 'جامعة الملك سعود',
        'major' => 'علوم الحاسب',
    ]);

    $this->getJson("/api/profile/{$owner->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.name', $owner->name)
        ->assertJsonPath('data.role', 'idea_owner')
        ->assertJsonPath('data.university', 'جامعة الملك سعود')
        ->assertJsonPath('data.major', 'علوم الحاسب')
        ->assertJsonMissingPath('data.email');
});

it('returns a public profile without the email for an investor', function () {
    $investor = User::factory()->investor()->create();

    $this->getJson("/api/profile/{$investor->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.name', $investor->name)
        ->assertJsonPath('data.role', 'investor')
        ->assertJsonPath('data.investment_focus', $investor->investment_focus)
        ->assertJsonMissingPath('data.email');
});

it('returns 404 for a nonexistent public profile', function () {
    $this->getJson('/api/profile/999999')
        ->assertStatus(404);
});
