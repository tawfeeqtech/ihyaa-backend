<?php

namespace Tests\Unit;

use App\Enums\InterestStatus;
use App\Models\Interest;
use App\Models\Project;
use App\Models\User;
use App\Services\InterestDuplicateGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * حارس التكرار التطبيقي — US-043 · T025.
 * الحالات النشطة (pending/accepted/accepted_pending_document) تمنع طلباً جديداً؛
 * الحالات النهائية (rejected/cancelled) تسمح به.
 */
beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $this->investor = User::factory()->investor()->create();
    $this->guard = app(InterestDuplicateGuard::class);
});

/** ينشئ طلباً بالحالة المحددة لنفس (project, investor). */
function guardInterest(Project $project, User $investor, InterestStatus $status): Interest
{
    return Interest::create([
        'project_id' => $project->id,
        'investor_id' => $investor->id,
        'interest_type' => 'investment',
        'status' => $status,
    ]);
}

it('detects an active duplicate while a request is pending (US-043)', function () {
    guardInterest($this->project, $this->investor, InterestStatus::PENDING);

    expect($this->guard->exists($this->project->id, $this->investor->id))->toBeTrue();
});

it('detects an active duplicate while a request is accepted (US-043)', function () {
    guardInterest($this->project, $this->investor, InterestStatus::ACCEPTED);

    expect($this->guard->exists($this->project->id, $this->investor->id))->toBeTrue();
});

it('treats accepted_pending_document as active (FR-310 · T052)', function () {
    guardInterest($this->project, $this->investor, InterestStatus::ACCEPTED_PENDING_DOCUMENT);

    expect($this->guard->exists($this->project->id, $this->investor->id))->toBeTrue();
});

it('allows a new request after the previous one was rejected (US-043)', function () {
    guardInterest($this->project, $this->investor, InterestStatus::REJECTED);

    expect($this->guard->exists($this->project->id, $this->investor->id))->toBeFalse();
});

it('allows a new request after the previous one was cancelled (US-043)', function () {
    guardInterest($this->project, $this->investor, InterestStatus::CANCELLED);

    expect($this->guard->exists($this->project->id, $this->investor->id))->toBeFalse();
});

it('does not block a different project or a different investor (US-043)', function () {
    guardInterest($this->project, $this->investor, InterestStatus::PENDING);

    $otherProject = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $otherInvestor = User::factory()->investor()->create();

    expect($this->guard->exists($otherProject->id, $this->investor->id))->toBeFalse();
    expect($this->guard->exists($this->project->id, $otherInvestor->id))->toBeFalse();
});

it('assertNoActive throws DuplicateInterestException only when a duplicate exists', function () {
    expect(fn () => $this->guard->assertNoActive($this->project->id, $this->investor->id))
        ->not->toThrow(\App\Exceptions\Interest\DuplicateInterestException::class);

    guardInterest($this->project, $this->investor, InterestStatus::PENDING);

    expect(fn () => $this->guard->assertNoActive($this->project->id, $this->investor->id))
        ->toThrow(\App\Exceptions\Interest\DuplicateInterestException::class);
});
