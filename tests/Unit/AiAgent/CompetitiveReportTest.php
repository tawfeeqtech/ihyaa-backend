<?php

namespace Tests\Unit\AiAgent;

use App\Enums\EvaluationStatus;
use App\Models\Category;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use App\Services\AI\CompetitiveReportGenerator;
use App\Services\AI\CompetitorSelector;
use App\Services\AI\PromptSanitizer;
use App\Services\AiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);
    config(['ai.mock' => true]);
});

it('produces a coherent market share range with assumptions and limitations (T113)', function () {
    $category = Category::factory()->create(['slug' => 'fintech']);
    $owner = User::factory()->ideaOwner()->create();
    $project = Project::factory()->published()->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'tags' => ['fintech'],
        'ai_score' => 70.0,
    ]);

    $competitors = [
        ['id' => 1, 'title' => 'A', 'ai_score' => 60.0, 'tag_overlap' => 1],
        ['id' => 2, 'title' => 'B', 'ai_score' => 65.0, 'tag_overlap' => 1],
    ];

    $generator = new CompetitiveReportGenerator(
        new AiGateway(),
        new CompetitorSelector(),
        new PromptSanitizer()
    );

    $share = $generator->marketShareEstimate($project, $competitors, null);

    expect($share['range_usd']['min'])->toBeGreaterThan(0);
    expect($share['range_usd']['min'])->toBeLessThan($share['range_usd']['max']);
    expect($share['range_usd']['max'])->toBeLessThanOrEqual($share['market_size_usd']['max']);
    expect($share['assumptions'])->not->toBeEmpty();
    expect($share['limitations'])->not->toBeEmpty();
    expect($share['share_percent'])->toBeGreaterThan(0);
});

it('falls back to default market size when sector data is missing (T113)', function () {
    $category = Category::factory()->create(['slug' => 'unknown-sector']);
    $owner = User::factory()->ideaOwner()->create();
    $project = Project::factory()->published()->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'tags' => ['unknown'],
        'ai_score' => 70.0,
    ]);

    $generator = new CompetitiveReportGenerator(
        new AiGateway(),
        new CompetitorSelector(),
        new PromptSanitizer()
    );

    $share = $generator->marketShareEstimate($project, [], null);

    expect($share['range_usd']['min'])->toBeLessThan($share['range_usd']['max']);
    expect(collect($share['limitations'])
        ->contains(fn ($l) => str_contains($l, 'الافتراضية')))->toBeTrue();
});

it('generates a full competitive report with market share from config (T111/T113)', function () {
    $category = Category::factory()->create(['slug' => 'fintech']);
    $owner = User::factory()->ideaOwner()->create();
    $project = Project::factory()->published()->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'tags' => ['fintech', 'ai'],
        'ai_score' => 70.0,
    ]);
    $evaluation = Evaluation::create([
        'project_id' => $project->id,
        'version' => 1,
        'status' => EvaluationStatus::COMPLETED,
        'overall_score' => 70.0,
    ]);

    $generator = new CompetitiveReportGenerator(
        new AiGateway(),
        new CompetitorSelector(),
        new PromptSanitizer()
    );

    $result = $generator->generate($project, $evaluation, null, 'ar');

    expect($result['competitive_advantage'])->not->toBeEmpty();
    expect($result['differentiators'])->not->toBeEmpty();
    expect($result['gaps_to_address'])->not->toBeEmpty();
    expect($result['recommendations'])->not->toBeEmpty();
    expect($result['comparison'])->toHaveKey('competitors');
    expect($result['comparison']['insufficient_data_note'])->toBeTrue();
    expect($result['market_share']['range_usd']['min'])->toBeLessThan($result['market_share']['range_usd']['max']);
    expect($result['_model_used'])->toBe('openai');
});
