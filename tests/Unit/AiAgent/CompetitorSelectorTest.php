<?php

namespace Tests\Unit\AiAgent;

use App\Enums\EvaluationStatus;
use App\Models\Category;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use App\Services\AI\CompetitorSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);
});

it('ranks competitors by tag overlap then by score closeness (T106)', function () {
    $category = Category::factory()->create();
    $owner = User::factory()->ideaOwner()->create();
    $target = Project::factory()->published()->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'tags' => ['fintech', 'ai'],
        'ai_score' => 70.0,
    ]);

    $make = function (array $tags, float $score) use ($category) {
        $competitor = Project::factory()->published()->create([
            'user_id' => User::factory()->ideaOwner()->create()->id,
            'category_id' => $category->id,
            'tags' => $tags,
            'ai_score' => $score,
        ]);
        Evaluation::create([
            'project_id' => $competitor->id,
            'version' => 1,
            'status' => EvaluationStatus::COMPLETED,
            'overall_score' => $score,
        ]);

        return $competitor;
    };

    // A: تقاطع 2 + فارق 5 · C: تقاطع 2 + فارق 10 · B: تقاطع 1 + فارق 1
    $a = $make(['fintech', 'ai'], 75.0);
    $c = $make(['fintech', 'ai'], 60.0);
    $b = $make(['fintech'], 71.0);

    $result = (new CompetitorSelector())->select($target);

    expect($result['count'])->toBe(3);
    expect($result['insufficient_data_note'])->toBeFalse();

    $ids = array_column($result['competitors'], 'id');
    expect($ids)->toBe([$a->id, $c->id, $b->id]);
});

it('flags insufficient_data_note when fewer than 3 competitors exist (T106)', function () {
    $category = Category::factory()->create();
    $owner = User::factory()->ideaOwner()->create();
    $target = Project::factory()->published()->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'tags' => ['fintech'],
        'ai_score' => 70.0,
    ]);

    $competitor = Project::factory()->published()->create([
        'user_id' => User::factory()->ideaOwner()->create()->id,
        'category_id' => $category->id,
        'tags' => ['fintech'],
        'ai_score' => 60.0,
    ]);
    Evaluation::create([
        'project_id' => $competitor->id,
        'version' => 1,
        'status' => EvaluationStatus::COMPLETED,
        'overall_score' => 60.0,
    ]);

    $result = (new CompetitorSelector())->select($target);

    expect($result['count'])->toBe(1);
    expect($result['insufficient_data_note'])->toBeTrue();
});

it('excludes self and un-evaluated same-category projects (T106)', function () {
    $category = Category::factory()->create();
    $owner = User::factory()->ideaOwner()->create();
    $target = Project::factory()->published()->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'tags' => ['fintech'],
        'ai_score' => 70.0,
    ]);

    // مشروع من نفس الفئة لكن بدون تقييم مكتمل
    Project::factory()->published()->create([
        'user_id' => User::factory()->ideaOwner()->create()->id,
        'category_id' => $category->id,
        'tags' => ['fintech'],
        'ai_score' => 80.0,
    ]);

    $result = (new CompetitorSelector())->select($target);

    expect($result['count'])->toBe(0);
    expect($result['insufficient_data_note'])->toBeTrue();
});

it('caps competitors at the configured limit (T106)', function () {
    $category = Category::factory()->create();
    $owner = User::factory()->ideaOwner()->create();
    $target = Project::factory()->published()->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'tags' => ['shared'],
        'ai_score' => 70.0,
    ]);

    foreach (range(1, 7) as $i) {
        $competitor = Project::factory()->published()->create([
            'user_id' => User::factory()->ideaOwner()->create()->id,
            'category_id' => $category->id,
            'tags' => ['shared'],
            'ai_score' => 60.0 + $i,
        ]);
        Evaluation::create([
            'project_id' => $competitor->id,
            'version' => 1,
            'status' => EvaluationStatus::COMPLETED,
            'overall_score' => $competitor->ai_score,
        ]);
    }

    $result = (new CompetitorSelector())->select($target);

    expect($result['count'])->toBe(5);
    expect(count($result['competitors']))->toBe(5);
});
