<?php

namespace Tests\Feature\Reports;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ReportFixtures;

uses(RefreshDatabase::class, ReportFixtures::class);

/**
 * المهارات المطلوبة — T100 (contracts/report-api.md §1 · US-027).
 * required_skills تبقى مسطّحة للتوافق، وteam_meta تصنّفها موجودة/ناقصة
 * من لقطة فريق المشروع، مع تحذير عند غياب بيانات الفريق.
 */
beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($this->owner);
});

it('classifies required skills as existing vs missing from the team snapshot (T100)', function () {
    $project = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'team' => [
            ['name' => 'سارة', 'role' => 'Backend Developer'],
            ['name' => 'محمد', 'role' => 'UI/UX Designer'],
        ],
    ]);
    $evaluation = $this->makeCompletedEvaluation($project);

    $this->getJson("/api/projects/{$project->id}/evaluations/{$evaluation->id}")
        ->assertStatus(200)
        // المسطّح (عقد) — متاح دائماً
        ->assertJsonPath('data.evaluation.required_skills.0', 'UI/UX Designer')
        ->assertJsonPath('data.evaluation.required_skills.1', 'Digital Marketing')
        // التصنيف من لقطة الفريق
        ->assertJsonPath('data.team_meta.has_team_data', true)
        ->assertJsonFragment(['existing_skills' => ['UI/UX Designer', 'Backend Developer']])
        ->assertJsonFragment(['missing_skills' => ['Digital Marketing']])
        ->assertJsonPath('data.team_meta.warning', null);
});

it('warns and marks all skills missing when the team snapshot is absent (T100)', function () {
    $project = Project::factory()->published()->create([
        'user_id' => $this->owner->id,
        'team' => null,
    ]);
    $evaluation = $this->makeCompletedEvaluation($project);

    $this->getJson("/api/projects/{$project->id}/evaluations/{$evaluation->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.team_meta.has_team_data', false)
        ->assertJsonPath('data.team_meta.existing_skills', [])
        ->assertJsonPath('data.team_meta.missing_skills', ['UI/UX Designer', 'Digital Marketing', 'Backend Developer'])
        ->assertJsonPath('data.team_meta.warning', 'معلومات الفريق غير كافية — قد يؤثر على دقة بُعد اكتمال الفريق');
});
