<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialUser;

uses(RefreshDatabase::class);

/**
 * يزرع حالة CSRF في Redis بنفس صيغة redirectToProvider ثم يعيد الـ state.
 * (State يُخزَّن بمفتاح oauth_state:{state} ويُستهلك استهلاكاً واحداً في callback.)
 */
function seedOAuthState(string $provider = 'google', ?string $redirectTo = null): string
{
    $state = Str::random(40);

    Redis::setex('oauth_state:'.$state, 600, json_encode([
        'provider' => $provider,
        'redirect_to' => $redirectTo ?? 'http://localhost:3000/ar/auth/callback',
    ]));

    return $state;
}

// ——————————————————————— redirectToProvider (US-007) ———————————————————————

it('returns a Socialite redirect URL for a valid provider', function () {
    Socialite::fake('google', SocialUser::fake([
        'id' => 'any',
        'email' => 'any@example.com',
    ]));

    $this->getJson('/api/auth/google?redirect_to=http://localhost:3000/ar/auth/callback')
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.redirect_url', 'https://socialite.fake/google/authorize');
});

it('rejects an unsupported provider with 404', function () {
    $this->getJson('/api/auth/facebook')
        ->assertStatus(404);
});

// ——————————————————————— handleProviderCallback (US-007) ———————————————————————

it('creates a new OAuth user with a pending role on first login', function () {
    Socialite::fake('google', SocialUser::fake([
        'id' => 'oauth-new-123',
        'email' => 'new.oauth@example.com',
        'name' => 'مستخدم جديد',
    ]));

    $state = seedOAuthState('google');

    $response = $this->get('/api/auth/google/callback?state='.$state);

    $response->assertStatus(302);

    $location = $response->headers->get('Location');
    expect($location)->toStartWith('http://localhost:3000/ar/auth/callback');

    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $params);

    expect($params)->toHaveKey('token');
    expect($params['role_required'])->toBe('1');
    expect($params['role_setup_state'])->not->toBe('');

    $this->assertDatabaseHas('users', [
        'email' => 'new.oauth@example.com',
        'provider' => 'google',
        'provider_id' => 'oauth-new-123',
        'role' => null,
    ]);

    $user = User::where('email', 'new.oauth@example.com')->first();
    expect($user->email_verified_at)->not->toBeNull();
});

it('logs in an existing OAuth user without duplicating the account', function () {
    $user = User::factory()->ideaOwner()->create([
        'email' => 'existing.oauth@example.com',
        'provider' => 'google',
        'provider_id' => 'oauth-existing-456',
    ]);

    Socialite::fake('google', SocialUser::fake([
        'id' => 'oauth-existing-456',
        'email' => 'existing.oauth@example.com',
        'name' => $user->name,
    ]));

    $state = seedOAuthState('google');

    $response = $this->get('/api/auth/google/callback?state='.$state);

    $response->assertStatus(302);

    $location = $response->headers->get('Location');
    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $params);

    expect($params['role'])->toBe('idea_owner');
    expect($params['role_required'])->toBe('0');

    $this->assertDatabaseCount('users', 1);
});

it('links an existing email account to the OAuth provider', function () {
    $user = User::factory()->ideaOwner()->create([
        'email' => 'link@example.com',
        'provider' => null,
        'provider_id' => null,
    ]);

    Socialite::fake('google', SocialUser::fake([
        'id' => 'oauth-link-789',
        'email' => 'link@example.com',
        'name' => $user->name,
    ]));

    $state = seedOAuthState('google');

    $this->get('/api/auth/google/callback?state='.$state)->assertStatus(302);

    $user->refresh();

    expect($user->provider)->toBe('google');
    expect($user->provider_id)->toBe('oauth-link-789');
});

it('redirects with OAUTH_FAILED when the provider rejects the request', function () {
    Socialite::fake('google', function () {
        throw new \Exception('provider is down');
    });

    $state = seedOAuthState('google');

    $response = $this->get('/api/auth/google/callback?state='.$state);

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toContain('error=OAUTH_FAILED');
});

it('redirects with PROVIDER_EMAIL_REQUIRED when the provider does not share an email', function () {
    Socialite::fake('google', SocialUser::fake([
        'id' => 'no-email-111',
        'email' => null,
        'name' => 'بلا بريد',
    ]));

    $state = seedOAuthState('google');

    $response = $this->get('/api/auth/google/callback?state='.$state);

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toContain('error=PROVIDER_EMAIL_REQUIRED');
});

it('rejects a callback without a CSRF state', function () {
    $response = $this->get('/api/auth/google/callback');

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toContain('error=INVALID_STATE');
});

it('rejects a callback whose provider does not match the state', function () {
    Socialite::fake('github', SocialUser::fake([
        'id' => 'x-123',
        'email' => 'x@example.com',
    ]));

    // state مخزّن لـ google لكن الـ callback على github
    $state = seedOAuthState('google');

    $response = $this->get('/api/auth/github/callback?state='.$state);

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toContain('error=INVALID_STATE');
});

it('consumes the CSRF state so it cannot be reused', function () {
    Socialite::fake('google', SocialUser::fake([
        'id' => 'once-222',
        'email' => 'once@example.com',
        'name' => 'مرة واحدة',
    ]));

    $state = seedOAuthState('google');

    $this->get('/api/auth/google/callback?state='.$state)->assertStatus(302);

    expect(Redis::get('oauth_state:'.$state))->toBeNull();
});

// ——————————————————————— finalizeRole (US-007 · SRS-F01-07) ———————————————————————

it('finalizes the role for a pending OAuth user with a valid state', function () {
    $user = User::factory()->oauthWithoutRole()->create();
    Sanctum::actingAs($user);

    $state = hash_hmac('sha256', $user->id.'|google', (string) config('app.key'));

    $this->postJson('/api/auth/google/role', [
        'role' => 'idea_owner',
        'state' => $state,
    ])
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.role', 'idea_owner');
});

it('rejects finalizing a role with a forged state', function () {
    $user = User::factory()->oauthWithoutRole()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/auth/google/role', [
        'role' => 'investor',
        'state' => 'forged-state-123',
    ])
        ->assertStatus(401)
        ->assertJsonPath('code', 'INVALID_STATE');
});

it('rejects finalizing a role when the role is already set', function () {
    $user = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/auth/google/role', [
        'role' => 'investor',
        'state' => 'whatever',
    ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'ROLE_ALREADY_SET');
});

it('forbids setting the admin role through the OAuth flow', function () {
    $user = User::factory()->oauthWithoutRole()->create();
    Sanctum::actingAs($user);

    $state = hash_hmac('sha256', $user->id.'|google', (string) config('app.key'));

    $this->postJson('/api/auth/google/role', [
        'role' => 'admin',
        'state' => $state,
    ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('rejects an invalid role value', function () {
    $user = User::factory()->oauthWithoutRole()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/auth/google/role', [
        'role' => 'superadmin',
        'state' => 'whatever',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role');
});
