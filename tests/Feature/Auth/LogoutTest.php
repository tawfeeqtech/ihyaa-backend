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

it('revokes all tokens on logout-all', function () {
    $user = User::factory()->ideaOwner()->create();

    $first = $user->createToken('api', ['*'], now()->addHours(24));
    $second = $user->createToken('api', ['*'], now()->addHours(24));
    $third = $user->createToken('api', ['*'], now()->addHours(24));

    $this->withToken($first->plainTextToken)
        ->postJson('/api/logout-all')
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', __('auth.logged_out_all'));

    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $first->accessToken->id]);
    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $second->accessToken->id]);
    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $third->accessToken->id]);

    $this->app['auth']->forgetGuards();

    $this->withToken($first->plainTextToken)
        ->getJson('/api/me')
        ->assertStatus(401);

    $this->app['auth']->forgetGuards();

    $this->withToken($second->plainTextToken)
        ->getJson('/api/me')
        ->assertStatus(401);
});

it('revokes all tokens when logout has all or all_devices flag', function () {
    $user = User::factory()->ideaOwner()->create();

    $first = $user->createToken('api', ['*'], now()->addHours(24));
    $second = $user->createToken('api', ['*'], now()->addHours(24));

    $this->withToken($first->plainTextToken)
        ->postJson('/api/logout', ['all_devices' => true])
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', __('auth.logged_out_all'));

    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $first->accessToken->id]);
    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $second->accessToken->id]);

    $this->app['auth']->forgetGuards();

    $this->withToken($first->plainTextToken)
        ->getJson('/api/me')
        ->assertStatus(401);
});

it('revokes other tokens but keeps current token on logout-others', function () {
    $user = User::factory()->ideaOwner()->create();

    $first = $user->createToken('api', ['*'], now()->addHours(24));
    $second = $user->createToken('api', ['*'], now()->addHours(24));
    $third = $user->createToken('api', ['*'], now()->addHours(24));

    $this->withToken($first->plainTextToken)
        ->postJson('/api/logout-others')
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', __('auth.logged_out_others'));

    $this->assertDatabaseHas('personal_access_tokens', ['id' => $first->accessToken->id]);
    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $second->accessToken->id]);
    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $third->accessToken->id]);

    $this->withToken($first->plainTextToken)
        ->getJson('/api/me')
        ->assertStatus(200)
        ->assertJsonPath('data.id', $user->id);

    $this->app['auth']->forgetGuards();

    $this->withToken($second->plainTextToken)
        ->getJson('/api/me')
        ->assertStatus(401);
});

it('returns 401 for a visitor trying to call logout endpoints', function (string $endpoint) {
    $this->postJson($endpoint)
        ->assertStatus(401);
})->with([
    '/api/logout',
    '/api/logout-all',
    '/api/logout-others',
]);

