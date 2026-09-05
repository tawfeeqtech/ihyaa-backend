<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);
});

// ——————————————————————— US-012: اقتراحات الوسوم (SRS-API-49) ———————————————————————

it('returns distinct tag suggestions from published projects', function () {
    Project::factory()->published()->create(['tags' => ['laravel', 'ai']]);
    Project::factory()->published()->create(['tags' => ['laravel', 'react']]);

    $response = $this->getJson('/api/tags/suggestions')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    expect($response->json('data'))
        ->toContain('laravel')
        ->toContain('ai')
        ->toContain('react');
});

it('filters suggestions by the q parameter', function () {
    Project::factory()->published()->create(['tags' => ['laravel', 'react']]);
    Project::factory()->published()->create(['tags' => ['react-native', 'ai']]);

    $response = $this->getJson('/api/tags/suggestions?q=react')
        ->assertStatus(200);

    expect($response->json('data'))
        ->toContain('react')
        ->toContain('react-native')
        ->not->toContain('laravel');
});

it('limits suggestions to 10', function () {
    Project::factory()->published()->create([
        'tags' => ['tag01', 'tag02', 'tag03', 'tag04', 'tag05', 'tag06'],
    ]);
    Project::factory()->published()->create([
        'tags' => ['tag07', 'tag08', 'tag09', 'tag10', 'tag11', 'tag12'],
    ]);

    $response = $this->getJson('/api/tags/suggestions')
        ->assertStatus(200);

    expect(count($response->json('data')))->toBe(10);
});

it('is publicly accessible without authentication', function () {
    Project::factory()->published()->create(['tags' => ['laravel']]);

    $this->getJson('/api/tags/suggestions')
        ->assertStatus(200)
        ->assertJsonPath('success', true);
});

it('does not include tags from draft projects', function () {
    Project::factory()->draft()->create(['tags' => ['secret-tag']]);
    Project::factory()->published()->create(['tags' => ['public-tag']]);

    $response = $this->getJson('/api/tags/suggestions')
        ->assertStatus(200);

    expect($response->json('data'))
        ->toContain('public-tag')
        ->not->toContain('secret-tag');
});

it('returns all suggestions when q is empty', function () {
    Project::factory()->published()->create(['tags' => ['laravel', 'react']]);

    $response = $this->getJson('/api/tags/suggestions?q=')
        ->assertStatus(200);

    expect($response->json('data'))
        ->toContain('laravel')
        ->toContain('react');
});
