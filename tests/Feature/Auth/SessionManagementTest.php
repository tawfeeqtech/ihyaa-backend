<?php

namespace Tests\Feature\Auth;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the list of active sessions with full metadata for the authenticated user', function () {
    $user = User::factory()->ideaOwner()->create();

    // الجلسة الأولى: كمبيوتر ويندوز مع كروم
    $current = $user->createToken('Windows Session', ['*'], now()->addHours(24));
    $current->accessToken->forceFill([
        'ip_address' => '82.205.10.20',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        'device_name' => 'Dell XPS',
        'location' => 'غزة، فلسطين',
        'last_used_at' => now()->subMinutes(5),
    ])->save();

    // الجلسة الثانية: هاتف آيفون مع سفاري
    $other = $user->createToken('iPhone Session', ['*'], now()->addHours(24));
    $other->accessToken->forceFill([
        'ip_address' => '82.205.10.21',
        'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
        'device_name' => 'iPhone 15',
        'location' => 'غزة، فلسطين',
        'last_used_at' => now()->subHours(2),
    ])->save();

    $response = $this->withToken($current->plainTextToken)
        ->getJson('/api/sessions')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    $data = $response->json('data');

    expect($data)->toHaveCount(2);

    // التحقق من الجلسة الحالية
    $currentSession = collect($data)->firstWhere('id', $current->accessToken->id);
    expect($currentSession['is_current'])->toBeTrue()
        ->and($currentSession['is_active'])->toBeTrue()
        ->and($currentSession['os'])->toBe('Windows 11')
        ->and($currentSession['browser'])->toBe('Chrome 128')
        ->and($currentSession['device']['name'])->toBe('Dell XPS')
        ->and($currentSession['location'])->toBe('غزة، فلسطين')
        ->and($currentSession['last_active_human'])->toContain('منذ');

    // التحقق من الجلسة الأخرى
    $otherSession = collect($data)->firstWhere('id', $other->accessToken->id);
    expect($otherSession['is_current'])->toBeFalse()
        ->and($otherSession['is_active'])->toBeTrue()
        ->and($otherSession['os'])->toBe('iOS 17.5')
        ->and($otherSession['browser'])->toBe('Safari 17')
        ->and($otherSession['device']['name'])->toBe('iPhone 15')
        ->and($otherSession['device']['type'])->toBe('mobile')
        ->and($otherSession['location'])->toBe('غزة، فلسطين');
});

it('allows terminating a specific session by id', function () {
    $user = User::factory()->ideaOwner()->create();

    $current = $user->createToken('current', ['*'], now()->addHours(24));
    $other = $user->createToken('other', ['*'], now()->addHours(24));

    $this->withToken($current->plainTextToken)
        ->deleteJson('/api/sessions/' . $other->accessToken->id)
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', __('auth.session_terminated'));

    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
    $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->id]);
});

it('prevents a user from terminating another users session', function () {
    $userA = User::factory()->ideaOwner()->create();
    $userB = User::factory()->ideaOwner()->create();

    $tokenA = $userA->createToken('userA', ['*'], now()->addHours(24));
    $tokenB = $userB->createToken('userB', ['*'], now()->addHours(24));

    $this->withToken($tokenA->plainTextToken)
        ->deleteJson('/api/sessions/' . $tokenB->accessToken->id)
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');

    $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenB->accessToken->id]);
});

it('populates device and location metadata automatically during login', function () {
    $user = User::factory()->ideaOwner()->create([
        'password' => bcrypt('Password123!'),
    ]);

    $response = $this->withServerVariables([
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
    ])->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'Password123!',
        'device_name' => 'My Windows Laptop',
    ])->assertStatus(200);

    $token = $response->json('data.token');

    $this->app['auth']->forgetGuards();

    $sessionsResponse = $this->withToken($token)
        ->withServerVariables([
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        ])
        ->getJson('/api/sessions')
        ->assertStatus(200);

    $firstSession = $sessionsResponse->json('data.0');

    expect($firstSession['device']['name'])->toBe('My Windows Laptop')
        ->and($firstSession['os'])->toBe('Windows 11')
        ->and($firstSession['browser'])->toBe('Chrome 128')
        ->and($firstSession['is_current'])->toBeTrue();
});

it('returns 401 for unauthenticated requests to sessions endpoints', function () {
    $this->getJson('/api/sessions')
        ->assertStatus(401);

    $this->deleteJson('/api/sessions/1')
        ->assertStatus(401);
});
