<?php

namespace Tests\Feature\Reports;

use App\Models\Project;
use App\Models\ReportExportLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ReportFixtures;

uses(RefreshDatabase::class, ReportFixtures::class);

/**
 * تصدير تقرير PDF — T103 (contracts/report-api.md §2 · US-028 · SRS-API-48).
 * 200 للمالك وبعد الاتفاق (عربي + إنجليزي) · 403 للمسجّل · سجل تدقيق في report_export_logs.
 */
beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $this->evaluation = $this->makeCompletedEvaluation($this->project);
});

it('exports a PDF for the owner in Arabic (T103 · US-028)', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson("/api/projects/{$this->project->id}/evaluations/{$this->evaluation->id}/report?lang=ar")
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="evaluation-report-'.$this->evaluation->id.'-ar.pdf"')
        ->assertHeader('Cache-Control', 'max-age=3600, private')
        ->assertSee('%PDF', false);
});

it('exports a PDF for the owner in English (T103 · US-028-S4)', function () {
    Sanctum::actingAs($this->owner);

    $this->get("/api/projects/{$this->project->id}/evaluations/{$this->evaluation->id}/report?lang=en")
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="evaluation-report-'.$this->evaluation->id.'-en.pdf"');
});

it('exports a PDF for a post-agreement investor (L3) (T103)', function () {
    $investor = User::factory()->investor()->create();
    $this->acceptInterest($this->project, $investor);

    Sanctum::actingAs($investor);

    $this->get("/api/projects/{$this->project->id}/evaluations/{$this->evaluation->id}/report?lang=ar")
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
});

it('forbids PDF export for a registered non-owner without agreement (L2) (T103 · US-028-S3)', function () {
    $investor = User::factory()->investor()->create();
    Sanctum::actingAs($investor);

    $this->getJson("/api/projects/{$this->project->id}/evaluations/{$this->evaluation->id}/report?lang=ar")
        ->assertStatus(403)
        ->assertJsonPath('code', 'PDF_EXPORT_DENIED');
});

it('returns 404 for a report export when the evaluation has no report (T103)', function () {
    $failed = \App\Models\Evaluation::create([
        'project_id' => $this->project->id,
        'version' => 2,
        'status' => \App\Enums\EvaluationStatus::FAILED,
        'error_log' => ['type' => 'all_providers_failed'],
    ]);

    Sanctum::actingAs($this->owner);

    $this->getJson("/api/projects/{$this->project->id}/evaluations/{$failed->id}/report?lang=ar")
        ->assertStatus(404);
});

it('logs every export request in report_export_logs without report content (T103 · US-028-S5)', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson("/api/projects/{$this->project->id}/evaluations/{$this->evaluation->id}/report?lang=ar")
        ->assertStatus(200);

    $log = ReportExportLog::where('evaluation_id', $this->evaluation->id)->first();

    expect($log)->not->toBeNull();
    expect($log->status)->toBe('success');
    expect($log->access_level)->toBe('EX');
    expect($log->language)->toBe('ar');
    expect((int) $log->user_id)->toBe((int) $this->owner->id);
    expect($log->evaluation_id)->toBe($this->evaluation->id);
});

it('logs denied export attempts with the actual disclosure level (T109)', function () {
    $investor = User::factory()->investor()->create();
    Sanctum::actingAs($investor);

    $this->getJson("/api/projects/{$this->project->id}/evaluations/{$this->evaluation->id}/report?lang=ar")
        ->assertStatus(403);

    $log = ReportExportLog::where('evaluation_id', $this->evaluation->id)->first();

    expect($log)->not->toBeNull();
    expect($log->status)->toBe('denied');
    expect($log->access_level)->toBe('L2');
});
