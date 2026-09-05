<?php

namespace Tests\Feature\Auth;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);
});

// ——————————————————————— US-006: حماية المسارات حسب الدور ———————————————————————

it('blocks an investor from an idea-owner route with 403', function () {
    $investor = User::factory()->investor()->create();
    Sanctum::actingAs($investor);

    $this->postJson('/api/projects', [])
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('returns 401 for a visitor on a protected route', function () {
    $this->postJson('/api/projects', [])
        ->assertStatus(401);
});

it('blocks an idea owner from an investor route with 403', function () {
    $owner = User::factory()->ideaOwner()->create();
    $project = Project::factory()->published()->create();

    Sanctum::actingAs($owner);

    $this->postJson("/api/projects/{$project->id}/interest", [])
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('blocks a non-admin user from an admin route with 403', function () {
    $owner = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($owner);

    $this->getJson('/api/admin/analytics')
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('returns 401 for a visitor on an admin route', function () {
    $this->getJson('/api/admin/analytics')
        ->assertStatus(401);
});

it('allows an admin on an admin route', function () {
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin);

    $this->getJson('/api/admin/analytics')
        ->assertStatus(200)
        ->assertJsonPath('success', true);
});

it('returns 409 ROLE_REQUIRED when a pending OAuth user hits a role route', function () {
    $user = User::factory()->oauthWithoutRole()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/projects', [])
        ->assertStatus(409)
        ->assertJsonPath('code', 'ROLE_REQUIRED');
});
