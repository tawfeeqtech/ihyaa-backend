<?php

use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\EvaluationInputSnapshot;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'scout.driver' => 'null',
        'services.python_evaluation.url' => 'http://python.test',
        'services.python_evaluation.api_key' => 'python-test-key',
        'services.python_evaluation.webhook_url' => 'http://laravel.test/api/webhooks/evaluations',
        'services.python_evaluation.webhook_secret' => 'webhook-secret',
    ]);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);

    Sanctum::actingAs($this->owner);
});

function evaluationRequestPayload(Project $project): array
{
    return ['project_id' => $project->id];
}

function fakePythonEvaluation(): void
{
    Http::fake([
        'http://python.test/api/v1/evaluations' => Http::response([
            'evaluation_id' => 'py-eval-123',
            'callback_id' => 'laravel-evaluation-1',
            'status' => 'queued',
        ], 202),
    ]);
}

it('requires Sanctum authentication', function () {
    $this->app['auth']->forgetGuards();

    $this->postJson('/api/integrations/evaluations', evaluationRequestPayload($this->project))
        ->assertUnauthorized();
});

it('requires the project owner and a published project', function () {
    $other = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($other);

    $this->withHeader('Idempotency-Key', 'owner-test-1')
        ->postJson('/api/integrations/evaluations', evaluationRequestPayload($this->project))
        ->assertForbidden();

    $this->project->forceFill(['publication_status' => 'draft'])->save();
    Sanctum::actingAs($this->owner);

    $this->withHeader('Idempotency-Key', 'draft-test-1')
        ->postJson('/api/integrations/evaluations', evaluationRequestPayload($this->project))
        ->assertStatus(422)
        ->assertJsonPath('code', 'UNEVALUABLE_PROJECT');
});

it('sends Laravel project data to Python without running a local evaluation job', function () {
    fakePythonEvaluation();

    $response = $this->withHeader('Idempotency-Key', 'external-test-1')
        ->postJson('/api/integrations/evaluations', evaluationRequestPayload($this->project));

    $response->assertCreated()
        ->assertJsonPath('data.project_id', $this->project->id)
        ->assertJsonPath('data.python_evaluation_id', 'py-eval-123')
        ->assertJsonPath('data.status', 'processing');

    $evaluation = Evaluation::where('external_idempotency_key', 'external-test-1')->firstOrFail();

    expect($evaluation->status)->toBe(EvaluationStatus::PROCESSING);
    expect(EvaluationInputSnapshot::where('evaluation_id', $evaluation->id)->value('external_input'))
        ->toBeArray();

    Http::assertSent(function ($request) use ($evaluation): bool {
        return $request->url() === 'http://python.test/api/v1/evaluations'
            && $request->header('X-API-Key')[0] === 'python-test-key'
            && $request['project_id'] === $evaluation->project_id
            && $request['callback_id'] === 'laravel-evaluation-'.$evaluation->id
            && ! array_key_exists('project_id', $request['project'])
            && $request['project']['title'] !== null;
    });
});

it('returns the existing evaluation for a repeated idempotency key', function () {
    fakePythonEvaluation();
    $headers = ['Idempotency-Key' => 'external-repeat-1'];

    $this->withHeaders($headers)
        ->postJson('/api/integrations/evaluations', evaluationRequestPayload($this->project))
        ->assertCreated();

    Http::fakeSequence()
        ->push(['unexpected' => true], 500);

    $this->withHeaders($headers)
        ->postJson('/api/integrations/evaluations', evaluationRequestPayload($this->project))
        ->assertOk()
        ->assertJsonPath('cached', true);

    expect(Evaluation::where('external_idempotency_key', 'external-repeat-1')->count())->toBe(1);
});
