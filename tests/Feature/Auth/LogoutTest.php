<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('revokes the current token on logout', function () {
    $user = User::factory()->ideaOwner()->create();

    $token = $user->createToken('api', ['*'], now()->addHours(24));

    $this->withToken($token->plainTextToken)
        ->postJson('/api/logout')
        ->assertStatus(200)          // السلوك الفعلي: noContent() تُرجع 200 (المواصفة تقول 204)
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
});

it('returns 401 when accessing a protected route with a revoked token', function () {
    $user = User::factory()->ideaOwner()->create();

    $token = $user->createToken('api', ['*'], now()->addHours(24));
    $token->accessToken->delete();

    $this->withToken($token->plainTextToken)
        ->getJson('/api/me')
        ->assertStatus(401);
});

it('keeps other tokens valid after logging out the current one', function () {
    $user = User::factory()->ideaOwner()->create();

    $first = $user->createToken('api', ['*'], now()->addHours(24));
    $second = $user->createToken('api', ['*'], now()->addHours(24));

    $this->withToken($first->plainTextToken)
        ->postJson('/api/logout')
        ->assertStatus(200);

    $this->withToken($second->plainTextToken)
        ->getJson('/api/me')
        ->assertStatus(200)
        ->assertJsonPath('data.id', $user->id);
});

it('returns 401 for a visitor trying to log out', function () {
    $this->postJson('/api/logout')
        ->assertStatus(401);
});
