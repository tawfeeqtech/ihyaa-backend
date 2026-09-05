<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

// ——————————————————————— forgot-password (US-004 · SRS-API-05) ———————————————————————

it('returns a unified 200 response for a registered email', function () {
    User::factory()->create(['email' => 'reset@example.com']);

    $this->postJson('/api/forgot-password', ['email' => 'reset@example.com'])
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.reset_link_sent', true);

    $this->assertDatabaseHas('password_resets', ['email' => 'reset@example.com']);
});

it('returns the same unified 200 response for an unregistered email', function () {
    $this->postJson('/api/forgot-password', ['email' => 'ghost@example.com'])
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.reset_link_sent', true);

    $this->assertDatabaseCount('password_resets', 0);
});

it('validates the email format', function () {
    $this->postJson('/api/forgot-password', ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

// ——————————————————————— reset-password (US-004 · SRS-API-06) ———————————————————————

it('resets the password and allows login with the new password', function () {
    User::factory()->create(['email' => 'reset@example.com']);

    $token = Password::broker()->createToken(
        User::where('email', 'reset@example.com')->first()
    );

    $this->postJson('/api/reset-password', [
        'email' => 'reset@example.com',
        'token' => $token,
        'password' => 'NewPassword123!',
        'password_confirmation' => 'NewPassword123!',
    ])
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.reset', true);

    $this->postJson('/api/login', [
        'email' => 'reset@example.com',
        'password' => 'NewPassword123!',
    ])
        ->assertStatus(200)
        ->assertJsonPath('data.user.email', 'reset@example.com');
});

it('rejects an invalid reset token', function () {
    User::factory()->create(['email' => 'reset@example.com']);

    $this->postJson('/api/reset-password', [
        'email' => 'reset@example.com',
        'token' => 'forged-token',
        'password' => 'NewPassword123!',
        'password_confirmation' => 'NewPassword123!',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_RESET_TOKEN');
});

it('rejects an expired reset token', function () {
    User::factory()->create(['email' => 'reset@example.com']);

    $token = Password::broker()->createToken(
        User::where('email', 'reset@example.com')->first()
    );

    // انتهاء الصلاحية — 60 دقيقة في config/auth.php
    DB::table('password_resets')
        ->where('email', 'reset@example.com')
        ->update(['created_at' => now()->subHours(2)]);

    $this->postJson('/api/reset-password', [
        'email' => 'reset@example.com',
        'token' => $token,
        'password' => 'NewPassword123!',
        'password_confirmation' => 'NewPassword123!',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_RESET_TOKEN');
});

it('rejects a password that does not match its confirmation', function () {
    User::factory()->create(['email' => 'reset@example.com']);

    $token = Password::broker()->createToken(
        User::where('email', 'reset@example.com')->first()
    );

    $this->postJson('/api/reset-password', [
        'email' => 'reset@example.com',
        'token' => $token,
        'password' => 'NewPassword123!',
        'password_confirmation' => 'DifferentPass!',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
});
