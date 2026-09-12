<?php

use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.python_evaluation.webhook_secret' => 'webhook-secret',
        'services.python_evaluation.webhook_tolerance_seconds' => 300,
        'scout.driver' => 'null',
    ]);
});

function webhookRequest(array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp = (string) time();

    return [
        'body' => $body,
        'headers' => [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'webhook-secret'),
        ],
    ];
}

function externalEvaluationForWebhook(Project $project): Evaluation
{
    return Evaluation::create([
        'project_id' => $project->id,
        'version' => 1,
        'status' => EvaluationStatus::PROCESSING,
        'external_idempotency_key' => 'external-webhook-1',
        'python_evaluation_id' => 'py-eval-123',
    ]);
}

it('accepts a signed completion webhook and stores the report', function () {
    $project = Project::factory()->published()->create();
    $evaluation = externalEvaluationForWebhook($project);
    $payload = [
        'event_id' => 'webhook-1',
        'callback_id' => 'laravel-evaluation-'.$evaluation->id,
        'python_evaluation_id' => 'py-eval-123',
        'project_id' => $project->id,
        'status' => 'completed',
        'overall_score' => 82.5,
        'confidence_score' => 90.0,
        'report' => [
            'schema_version' => '1.0',
            'overall_score' => 82.5,
            'dimensions' => [],
            'gap_analysis' => [],
            'recommendations' => [],
            'required_skills' => [],
            'warnings' => [],
        ],
    ];
    $request = webhookRequest($payload);

    $this->call('POST', '/api/webhooks/evaluations', [], [], [], $request['headers'], $request['body'])
        ->assertOk()
        ->assertJsonPath('data.evaluation_id', $evaluation->id)
        ->assertJsonPath('data.status', 'completed');

    expect($evaluation->fresh()->status)->toBe(EvaluationStatus::COMPLETED);
    expect($evaluation->fresh()->overall_score)->toBe(82.5);
    expect($project->fresh()->ai_score)->toBe(82.5);
});

it('accepts a duplicate webhook without applying it twice', function () {
    $project = Project::factory()->published()->create();
    $evaluation = externalEvaluationForWebhook($project);
    $payload = [
        'event_id' => 'webhook-duplicate',
        'callback_id' => 'laravel-evaluation-'.$evaluation->id,
        'python_evaluation_id' => 'py-eval-123',
        'project_id' => $project->id,
        'status' => 'completed',
        'overall_score' => 70.0,
        'report' => ['overall_score' => 70.0],
    ];
    $request = webhookRequest($payload);

    $this->call('POST', '/api/webhooks/evaluations', [], [], [], $request['headers'], $request['body'])
        ->assertOk();

    $duplicate = webhookRequest(array_merge($payload, ['overall_score' => 99.0]));

    $this->call('POST', '/api/webhooks/evaluations', [], [], [], $duplicate['headers'], $duplicate['body'])
        ->assertOk();

    expect($evaluation->fresh()->overall_score)->toBe(70.0);
});

it('rejects an invalid webhook signature', function () {
    $payload = [
        'event_id' => 'webhook-invalid',
        'callback_id' => 'laravel-evaluation-1',
        'python_evaluation_id' => 'py-eval-123',
        'project_id' => 1,
        'status' => 'completed',
    ];
    $request = webhookRequest($payload);
    $request['headers']['HTTP_X_WEBHOOK_SIGNATURE'] = 'sha256=invalid';

    $this->call('POST', '/api/webhooks/evaluations', [], [], [], $request['headers'], $request['body'])
        ->assertUnauthorized();
});
