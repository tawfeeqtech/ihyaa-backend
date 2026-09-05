<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('logs in a verified user and returns a Sanctum token', function () {
    User::factory()->ideaOwner()->create(['email' => 'login@example.com']);

    $this->postJson('/api/login', [
        'email' => 'login@example.com',
        'password' => 'password',
    ])
        ->assertStatus(200)
        ->assertJsonStructure(['data' => ['token', 'token_expires_at', 'user']])
        ->assertJsonPath('data.user.email', 'login@example.com')
        ->assertJsonPath('data.user.role', 'idea_owner')
        ->assertJsonPath('data.user.email_verified', true);
});

it('returns 401 INVALID_CREDENTIALS for a wrong password', function () {
    User::factory()->create(['email' => 'wrongpw@example.com']);

    $this->postJson('/api/login', [
        'email' => 'wrongpw@example.com',
        'password' => 'wrong-password',
    ])
        ->assertStatus(401)
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

it('returns 401 EMAIL_NOT_VERIFIED for an unverified account', function () {
    User::factory()->unverified()->create(['email' => 'unverified@example.com']);

    $this->postJson('/api/login', [
        'email' => 'unverified@example.com',
        'password' => 'password',
    ])
        ->assertStatus(401)
        ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
});

it('returns 401 INVALID_CREDENTIALS for a nonexistent email without revealing it', function () {
    $this->postJson('/api/login', [
        'email' => 'nobody@example.com',
        'password' => 'password',
    ])
        ->assertStatus(401)
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});
