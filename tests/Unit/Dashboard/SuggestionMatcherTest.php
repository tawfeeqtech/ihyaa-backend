<?php

namespace Tests\Unit\Dashboard;

use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use App\Services\Dashboard\SuggestionMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * SuggestionMatcher — T078 · US-056 (dashboard-api.md §2 · data-model §4).
 *
 * مطابقة بسيطة بلا ML: أولوية القطاع ← درجة AI ← الحداثة · حد 10 ·
 * استبعاد مشاريع المستثمر نفسه · ملف فارغ → fallback بأفضل الدرجات ·
 * badge التفاعل (sent أولوية على saved).
 */

/** ينشئ تصنيفاً بمعرّف صريح (يتجاوز تسلسل CategoryFactory). */
function suggestionCategory(string $slug, string $nameAr, string $nameEn): Category
{
    return Category::factory()->create([
        'slug' => $slug,
        'name_ar' => $nameAr,
        'name_en' => $nameEn,
    ]);
}

beforeEach(function () {
    config(['scout.driver' => 'null']);
    $this->matcher = app(SuggestionMatcher::class);
    $this->owner = User::factory()->ideaOwner()->create();
});

it('prioritises the sector match over a higher AI score (US-056/1)', function () {
    $fintech = suggestionCategory('fintech', 'التقنية المالية', 'Fintech');
    $health = suggestionCategory('healthtech', 'التقنية الصحية', 'Healthtech');

    $fintechProject = Project::factory()->published()->create([
        'category_id' => $fintech->id,
        'user_id' => $this->owner->id,
        'ai_score' => 50,
    ]);
    $healthProject = Project::factory()->published()->create([
        'category_id' => $health->id,
        'user_id' => $this->owner->id,
        'ai_score' => 90,
    ]);

    $investor = User::factory()->investor()->create(['preferred_sectors' => ['التقنية المالية']]);

    $ids = $this->matcher->match($investor)->pluck('id')->values()->all();

    expect($ids)->toBe([$fintechProject->id, $healthProject->id]);
});

it('sorts by score descending within the same sector', function () {
    $fintech = suggestionCategory('fintech', 'التقنية المالية', 'Fintech');

    $low = Project::factory()->published()->create(['category_id' => $fintech->id, 'user_id' => $this->owner->id, 'ai_score' => 60]);
    $high = Project::factory()->published()->create(['category_id' => $fintech->id, 'user_id' => $this->owner->id, 'ai_score' => 85]);

    $investor = User::factory()->investor()->create(['preferred_sectors' => ['التقنية المالية']]);

    $ids = $this->matcher->match($investor)->pluck('id')->values()->all();

    expect($ids)->toBe([$high->id, $low->id]);
});

it('breaks ties by recency (newest first) when scores are equal', function () {
    $fintech = suggestionCategory('fintech', 'التقنية المالية', 'Fintech');

    $older = Project::factory()->published()->create(['category_id' => $fintech->id, 'user_id' => $this->owner->id, 'ai_score' => 70]);
    $newer = Project::factory()->published()->create(['category_id' => $fintech->id, 'user_id' => $this->owner->id, 'ai_score' => 70]);

    // ترتيب قطعي: أرجِع إنشاء الأقدم إلى ساعة قبل الأحدث.
    $older->created_at = now()->subHour();
    $older->save();

    $investor = User::factory()->investor()->create(['preferred_sectors' => ['التقنية المالية']]);

    $ids = $this->matcher->match($investor)->pluck('id')->values()->all();

    expect($ids)->toBe([$newer->id, $older->id]);
});

it('caps the suggestions at 10 (SRS-F11-01)', function () {
    $fintech = suggestionCategory('fintech', 'التقنية المالية', 'Fintech');

    foreach (range(1, 14) as $i) {
        Project::factory()->published()->create(['category_id' => $fintech->id, 'user_id' => $this->owner->id, 'ai_score' => $i]);
    }

    $investor = User::factory()->investor()->create(['preferred_sectors' => ['التقنية المالية']]);

    $suggestions = $this->matcher->match($investor);

    expect($suggestions)->toHaveCount(10);
});

it('never suggests the investor\'s own projects (US-056/1 · contract §2)', function () {
    $fintech = suggestionCategory('fintech', 'التقنية المالية', 'Fintech');

    $investor = User::factory()->investor()->create(['preferred_sectors' => ['التقنية المالية']]);
    $ownProject = Project::factory()->published()->create(['category_id' => $fintech->id, 'user_id' => $investor->id, 'ai_score' => 99]);

    $otherProject = Project::factory()->published()->create(['category_id' => $fintech->id, 'user_id' => $this->owner->id, 'ai_score' => 50]);

    $ids = $this->matcher->match($investor)->pluck('id')->values()->all();

    expect($ids)->toBe([$otherProject->id]);
    expect($ids)->not->toContain($ownProject->id);
});

it('falls back to the best scores when the investor profile is empty (US-056/4)', function () {
    Project::factory()->published()->create(['user_id' => $this->owner->id, 'ai_score' => 55]);
    $best = Project::factory()->published()->create(['user_id' => $this->owner->id, 'ai_score' => 92]);

    $investor = User::factory()->investor()->create(['preferred_sectors' => []]);

    $ids = $this->matcher->match($investor)->pluck('id')->values()->all();

    expect($ids[0])->toBe($best->id);
});

it('matches a project by tag intersection (data-model §4.2)', function () {
    $tagged = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'tags' => ['التقنية المالية'],
        'ai_score' => 40,
    ]);
    Project::factory()->published()->create(['user_id' => $this->owner->id, 'ai_score' => 95]);

    $investor = User::factory()->investor()->create(['preferred_sectors' => ['التقنية المالية']]);

    $ids = $this->matcher->match($investor)->pluck('id')->values()->all();

    expect($ids[0])->toBe($tagged->id);
});

it('builds engagement badges with sent taking priority over saved (US-056/5)', function () {
    $fintech = suggestionCategory('fintech', 'التقنية المالية', 'Fintech');

    $owner = User::factory()->ideaOwner()->create();
    $sentProject = Project::factory()->published()->create(['category_id' => $fintech->id, 'user_id' => $owner->id]);
    $savedProject = Project::factory()->published()->create(['category_id' => $fintech->id, 'user_id' => $owner->id]);
    $bothProject = Project::factory()->published()->create(['category_id' => $fintech->id, 'user_id' => $owner->id]);
    $noneProject = Project::factory()->published()->create(['category_id' => $fintech->id, 'user_id' => $owner->id]);

    $investor = User::factory()->investor()->create(['preferred_sectors' => ['التقنية المالية']]);

    // sent + saved على نفس المشروع → sent أولوية.
    $investor->interestsSent()->create([
        'project_id' => $sentProject->id,
        'interest_type' => 'investment',
        'status' => 'pending',
    ]);
    $investor->interestsSent()->create([
        'project_id' => $bothProject->id,
        'interest_type' => 'investment',
        'status' => 'pending',
    ]);
    $investor->savedProjects()->create(['project_id' => $bothProject->id]);
    $investor->savedProjects()->create(['project_id' => $savedProject->id]);

    $badges = $this->matcher->engagementBadges($investor);

    expect($badges[$sentProject->id])->toBe('sent');
    expect($badges[$savedProject->id])->toBe('saved');
    expect($badges[$bothProject->id])->toBe('sent');   // sent أولوية على saved
    expect($badges[$noneProject->id] ?? null)->toBeNull();
});
