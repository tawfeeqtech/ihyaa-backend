<?php

namespace Tests\Feature\Evaluation;

use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * T079 — كشف التغييرات الجوهرية في PUT /api/projects/{project} (contract §PUT · FR-226/227/228).
 *
 * الحقول الجوهرية: description · tags · github_url · status.
 * أي تغيير فيها = significant_changes: true + prompt — ولا يبدأ أي تقييم تلقائي (FR-227).
 */

/** رسالة الاقتراح (contract §PUT) — تُفحص حرفياً في الرد. */
const SIGNIFICANT_CHANGES_PROMPT = 'لقد أجريت تغييرات جوهرية. هل تريد إعادة تقييم مشروعك؟';

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'tags' => ['laravel'],
        'github_url' => 'https://github.com/acme/legacy',
    ]);

    Sanctum::actingAs($this->owner);
});

it('flags significant_changes and returns the prompt when the description changes (FR-226)', function () {
    $this->putJson("/api/projects/{$this->project->id}", [
        'description' => 'وصف جديد كلياً يغيّر جوهر المشروع — وصف جديد كلياً يغيّر جوهر المشروع — وصف جديد كلياً يغيّر جوهر المشروع.',
    ])
        ->assertStatus(200)
        ->assertJsonPath('data.significant_changes', true)
        ->assertJsonPath('data.prompt', SIGNIFICANT_CHANGES_PROMPT);
});

it('flags significant_changes when tags change', function () {
    $this->putJson("/api/projects/{$this->project->id}", ['tags' => ['react', 'ai']])
        ->assertStatus(200)
        ->assertJsonPath('data.significant_changes', true)
        ->assertJsonPath('data.prompt', SIGNIFICANT_CHANGES_PROMPT);
});

it('flags significant_changes when github_url changes', function () {
    $this->putJson("/api/projects/{$this->project->id}", [
        'github_url' => 'https://github.com/acme/revival',
    ])
        ->assertStatus(200)
        ->assertJsonPath('data.significant_changes', true)
        ->assertJsonPath('data.prompt', SIGNIFICANT_CHANGES_PROMPT);
});

it('flags significant_changes when status changes', function () {
    $this->putJson("/api/projects/{$this->project->id}", ['status' => 'completed'])
        ->assertStatus(200)
        ->assertJsonPath('data.significant_changes', true)
        ->assertJsonPath('data.prompt', SIGNIFICANT_CHANGES_PROMPT);
});

it('keeps significant_changes false and omits the prompt when only the title changes (FR-226)', function () {
    $this->putJson("/api/projects/{$this->project->id}", [
        'title' => 'عنوان جديد لا يمس الحقول الجوهرية',
    ])
        ->assertStatus(200)
        ->assertJsonPath('data.significant_changes', false)
        ->assertJsonMissingPath('data.prompt');
});

it('does not start an evaluation automatically on significant changes (FR-227)', function () {
    $this->putJson("/api/projects/{$this->project->id}", [
        'description' => 'وصف جديد كلياً يغيّر جوهر المشروع — وصف جديد كلياً يغيّر جوهر المشروع — وصف جديد كلياً يغيّر جوهر المشروع.',
    ])
        ->assertStatus(200)
        ->assertJsonPath('data.significant_changes', true)
        ->assertJsonPath('data.prompt', SIGNIFICANT_CHANGES_PROMPT);

    // القرار بيد المستخدم — لا تقييم تلقائي.
    expect(Evaluation::count())->toBe(0);
});
