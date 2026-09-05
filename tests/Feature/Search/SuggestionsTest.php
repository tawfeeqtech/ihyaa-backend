<?php

namespace Tests\Feature\Search;

use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);
uses(InteractsWithFakeSearch::class);

beforeEach(function () {
    $this->useFakeSearchEngine();
    Cache::flush();

    $this->owner = User::factory()->ideaOwner()->create();
    $this->health = Category::factory()->create(['slug' => 'health', 'name_ar' => 'الصحة']);

    Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'category_id' => $this->health->id,
        'title' => 'منصة تشخيص طبي بالذكاء الاصطناعي',
        'description' => 'نظام يحلل الأشعة الطبية.',
        'tags' => ['تشخيص', 'ai'],
    ]);

    Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'category_id' => $this->health->id,
        'title' => 'تطبيق تشخيص الأمراض الجلدية',
        'description' => 'تطبيق للكشف المبكر عن الأمراض.',
        'tags' => ['تشخيص', 'health'],
    ]);

    Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'category_id' => $this->health->id,
        'title' => 'منصة تمويل المشاريع الناشئة',
        'description' => 'تربط رواد الأعمال بالمستثمرين.',
        'tags' => ['fintech'],
    ]);
});

// ———————————————————————— T130 ————————————————————————

it('returns up to 5 suggestions with project_title and tag types, no duplicates', function () {
    $response = $this->getJson('/api/search/suggestions?q=تشخ')
        ->assertStatus(200)
        ->assertJsonPath('data.query', 'تشخ')
        ->assertJsonPath('data.suggestions.0.type', 'project_title')
        ->assertJsonPath('data.suggestions.0.text', 'منصة تشخيص طبي بالذكاء الاصطناعي')
        ->assertJsonPath('data.suggestions.1.type', 'tag')
        ->assertJsonPath('data.suggestions.1.text', 'تشخيص')
        ->assertJsonPath('data.suggestions.2.type', 'project_title')
        ->assertJsonCount(3, 'data.suggestions');

    expect(count($response->json('data.suggestions')))->toBeLessThanOrEqual(5);
});

it('returns highlighted snippets wrapped in <strong> for project titles', function () {
    $this->getJson('/api/search/suggestions?q=تشخ')
        ->assertStatus(200)
        ->assertJsonPath('data.suggestions.0.highlighted', 'منصة <strong>تشخ</strong>يص طبي بالذكاء الاصطناعي')
        ->assertJsonPath('data.suggestions.0.project_id', 1);
});

it('returns 422 QUERY_TOO_SHORT for a single character', function () {
    $this->getJson('/api/search/suggestions?q=ت')
        ->assertStatus(422)
        ->assertJsonPath('code', 'QUERY_TOO_SHORT')
        ->assertJsonPath('errors.q.0', 'الحد الأدنى حرفان');
});

it('caches suggestions in Redis under search:suggestions:{q}', function () {
    $this->getJson('/api/search/suggestions?q=تشخ')->assertStatus(200);

    expect(Cache::has('search:suggestions:تشخ'))->toBeTrue();
    expect(Cache::get('search:suggestions:تشخ'))->toBeArray()
        ->toHaveKey('suggestions');
});
