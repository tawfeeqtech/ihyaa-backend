<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** بيانات تسجيل صالحة — تُعدَّل حسب الحالة (T031/T036/T037). */
function registerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'سارة الأحمدي',
        'email' => 'sara@example.com',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
        'role' => 'idea_owner',
    ], $overrides);
}

it('registers an idea owner with 201 and defers the token until email verification', function () {
    $this->postJson('/api/register', registerPayload())
        ->assertStatus(201)
        ->assertJsonPath('data.otp_required', true)
        ->assertJsonMissingPath('data.token')
        ->assertJsonMissingPath('data.user');

    $this->assertDatabaseHas('users', [
        'email' => 'sara@example.com',
        'role' => UserRole::IDEA_OWNER->value,
    ]);

    $user = User::where('email', 'sara@example.com')->first();

    expect($user->email_verified_at)->toBeNull();
});

it('creates an in-app welcome notification on registration (T144)', function () {
    $this->postJson('/api/register', registerPayload())
        ->assertStatus(201);

    $user = User::where('email', 'sara@example.com')->first();

    $this->assertDatabaseHas('notifications', [
        'user_id' => $user->id,
        'type' => 'welcome',
        'is_critical' => false,
        'read_at' => null,
    ]);
});

it('registers an investor with an investment profile', function () {
    $this->postJson('/api/register', registerPayload([
        'name' => 'خالد العمري',
        'email' => 'khaled@example.com',
        'role' => 'investor',
        'investment_focus' => 'Fintech',
        'investment_range' => ['min' => 50000, 'max' => 500000],
    ]))
        ->assertStatus(201)
        ->assertJsonPath('data.otp_required', true);

    $this->assertDatabaseHas('users', [
        'email' => 'khaled@example.com',
        'role' => UserRole::INVESTOR->value,
        'investment_focus' => 'Fintech',
    ]);
});

it('returns 422 for a duplicate email', function () {
    User::factory()->create(['email' => 'duplicate@example.com']);

    $this->postJson('/api/register', registerPayload(['email' => 'duplicate@example.com']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('returns 422 for an invalid role', function () {
    $this->postJson('/api/register', registerPayload(['role' => 'guest']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('role');
});

it('returns 403 when trying to register an admin account', function () {
    $this->postJson('/api/register', registerPayload([
        'email' => 'admin@example.com',
        'role' => 'admin',
    ]))
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('returns 422 when the password confirmation does not match', function () {
    $this->postJson('/api/register', registerPayload([
        'password_confirmation' => 'DifferentPass123!',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
});
