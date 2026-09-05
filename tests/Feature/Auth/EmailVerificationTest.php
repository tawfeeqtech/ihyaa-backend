<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('verifies the email with a valid OTP and issues a token', function () {
    $user = User::factory()->unverified()->create(['email' => 'verify@example.com']);
    $code = $user->generateOtp();

    $this->postJson('/api/email/verify', [
        'email' => 'verify@example.com',
        'code' => $code,
    ])
        ->assertStatus(200)
        ->assertJsonStructure(['data' => ['token', 'token_expires_at', 'user']])
        ->assertJsonPath('data.user.email', 'verify@example.com')
        ->assertJsonPath('data.user.email_verified', true);

    expect($user->fresh()->email_verified_at)->not->toBeNull();
    expect($user->fresh()->otp_code)->toBeNull();
});

it('returns 422 OTP_INVALID for a wrong code', function () {
    $user = User::factory()->unverified()->create(['email' => 'wrong@example.com']);
    $user->generateOtp();

    $this->postJson('/api/email/verify', [
        'email' => 'wrong@example.com',
        'code' => '000000',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'OTP_INVALID');
});

it('returns 422 OTP_EXPIRED when the code has expired', function () {
    $user = User::factory()->unverified()->create(['email' => 'expired@example.com']);
    $user->generateOtp();
    $user->forceFill(['otp_expires_at' => now()->subSecond()])->save();

    $this->postJson('/api/email/verify', [
        'email' => 'expired@example.com',
        'code' => '123456',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'OTP_EXPIRED');
});

it('returns 429 OTP_BLOCKED after three failed attempts', function () {
    $user = User::factory()->unverified()->create(['email' => 'blocked@example.com']);
    $user->generateOtp();
    $user->forceFill(['otp_attempts' => 3])->save();

    $this->postJson('/api/email/verify', [
        'email' => 'blocked@example.com',
        'code' => '123456',
    ])
        ->assertStatus(429)
        ->assertJsonPath('code', 'OTP_BLOCKED');
});

it('reports an already-verified email', function () {
    User::factory()->create(['email' => 'already@example.com']);

    $this->postJson('/api/email/verify', [
        'email' => 'already@example.com',
        'code' => '123456',
    ])
        ->assertStatus(200)
        ->assertJsonPath('data.verified', true);
});

it('resends a new OTP when the code is omitted', function () {
    $user = User::factory()->unverified()->create(['email' => 'resend@example.com']);
    $user->generateOtp();

    $this->postJson('/api/email/verify', [
        'email' => 'resend@example.com',
    ])
        ->assertStatus(200)
        ->assertJsonPath('data.otp_sent', true);
});

it('rejects a code that is not 6 digits', function () {
    $user = User::factory()->unverified()->create(['email' => 'badformat@example.com']);
    $user->generateOtp();

    $this->postJson('/api/email/verify', [
        'email' => 'badformat@example.com',
        'code' => '12ab',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});
