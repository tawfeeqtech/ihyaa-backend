<?php

namespace Tests\Integration\Dashboard;

use App\Enums\EvaluationStatus;
use App\Enums\InterestStatus;
use App\Models\Evaluation;
use App\Models\Interest;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Services\Dashboard\IdeaOwnerDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * T056 · US-051..053 — تكامل خدمة اللوحة (IdeaOwnerDashboardService) مع قاعدة
 * بيانات حقيقية: kpis (أربعة مؤشرات) + بطاقات المشاريع + تغذية الأحداث.
 */

beforeEach(function () {
    config(['scout.driver' => 'null']);
    $this->service = app(IdeaOwnerDashboardService::class);
    $this->owner = User::factory()->ideaOwner()->create();
});

it('composes kpis, mini-cards and feed into the dashboard payload', function () {
    $p = Project::factory()->published()->create(['user_id' => $this->owner->id, 'ai_score' => 75]);

    Evaluation::create([
        'project_id' => $p->id,
        'version' => 1,
        'status' => EvaluationStatus::COMPLETED->value,
        'overall_score' => 75,
        'completed_at' => now(),
    ]);

    // طلباهتمان مقبول على المشروع.
    Interest::create([
        'project_id' => $p->id,
        'investor_id' => User::factory()->investor()->create()->id,
        'interest_type' => 'investment',
        'status' => InterestStatus::ACCEPTED,
    ]);

    // حدث تقييم مكتمل يظهر في التغذية (evaluation_completed).
    Notification::create([
        'user_id' => $this->owner->id,
        'type' => 'evaluation_completed',
        'title' => 'اكتمل التقييم',
        'body' => 'تم تقييم مشروعك',
        'data' => ['project_id' => $p->id, 'url' => '/projects/'.$p->id],
    ]);

    $payload = $this->service->dataFor($this->owner);

    expect($payload['kpis']['total_projects'])->toBe(1);
    expect($payload['kpis']['average_score'])->toBe(75.0);
    expect($payload['kpis']['average_score_note'])->toBeNull();
    expect($payload['kpis']['total_requests_received'])->toBe(1);
    expect($payload['kpis']['accepted_requests'])->toBe(1);

    expect($payload['projects'])->toHaveCount(1);
    expect($payload['projects'][0]['id'])->toBe($p->id);
    expect($payload['projects'][0]['evaluation_status'])->toBe('completed');
    expect($payload['projects'][0]['ai_score'])->toBe(75.0);

    expect($payload['feed']['items'])->toHaveCount(1);
    expect($payload['feed']['items'][0]['type'])->toBe('evaluation_completed');
    expect($payload['feed']['items'][0]['related_project']['id'])->toBe($p->id);
});
