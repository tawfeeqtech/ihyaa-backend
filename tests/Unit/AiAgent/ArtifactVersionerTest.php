<?php

namespace Tests\Unit\AiAgent;

use App\Models\AiAgentArtifact;
use App\Models\Project;
use App\Models\User;
use App\Services\AI\ArtifactVersioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);
});

it('increments version per (project, type) starting from 1 (T114)', function () {
    $project = Project::factory()->published()->create([
        'user_id' => User::factory()->ideaOwner()->create()->id,
    ]);

    $versioner = new ArtifactVersioner();

    $v1 = $versioner->createProcessing($project->id, 'swot');
    $v2 = $versioner->createProcessing($project->id, 'swot');

    expect($v1->version)->toBe(1);
    expect($v2->version)->toBe(2);

    // نوع مختلف يبدأ من 1 لنفس المشروع
    $c1 = $versioner->createProcessing($project->id, 'comparison');
    expect($c1->version)->toBe(1);
});

it('computes max+1 even when failed versions exist (T114)', function () {
    $project = Project::factory()->published()->create([
        'user_id' => User::factory()->ideaOwner()->create()->id,
    ]);

    AiAgentArtifact::create([
        'project_id' => $project->id,
        'analysis_type' => 'swot',
        'artifact_data' => [],
        'version' => 1,
        'status' => 'completed',
    ]);
    AiAgentArtifact::create([
        'project_id' => $project->id,
        'analysis_type' => 'swot',
        'artifact_data' => [],
        'version' => 2,
        'status' => 'failed',
    ]);

    expect((new ArtifactVersioner())->nextVersion($project->id, 'swot'))->toBe(3);
});

it('creates a processing artifact with the right defaults (T114/T103)', function () {
    $project = Project::factory()->published()->create([
        'user_id' => User::factory()->ideaOwner()->create()->id,
    ]);

    $artifact = (new ArtifactVersioner())->createProcessing($project->id, 'swot', 'en');

    expect($artifact->project_id)->toBe($project->id);
    expect($artifact->analysis_type->value)->toBe('swot');
    expect($artifact->status->value)->toBe('processing');
    expect($artifact->language)->toBe('en');
    expect($artifact->artifact_data)->toBe([]);
    expect($artifact->error_message)->toBeNull();
});
