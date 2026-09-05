<?php

namespace Tests\Feature\Interest;

use App\Exceptions\Interest\DuplicateInterestException;
use App\Models\Interest;
use App\Models\Project;
use App\Models\User;
use App\Services\InterestDuplicateGuard;
use App\Services\InterestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

/**
 * منع الطلب النشط المكرر — US-043 · T042/T043 · contract §1.
 *
 * طبقتا دفاع: InterestDuplicateGuard (تطبيقية) + الفهرس الفريد
 * (project_id, active_dup_key) على قاعدة البيانات. هنا نعطّل الطبقة
 * التطبيقية (Mockery) لمحاكاة السباق — حيث يتجاوز الطلبان الفحص معاً —
 * فتلتقطه طبقة قاعدة البيانات → DuplicateInterestException + سجل واحد فقط.
 */
beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);
    $this->investor = User::factory()->investor()->create();
});

it('prevents a duplicate active interest via the DB unique index when the guard passes (T042 · US-043)', function () {
    // نعطّل طبقة التطبيق — كأن الطلبين دخلا المعاملة معاً قبل فحص التكرار.
    $guard = Mockery::mock(InterestDuplicateGuard::class);
    $guard->shouldReceive('assertNoActive')->andReturnNull();
    $guard->shouldReceive('exists')->andReturn(false);
    $this->app->instance(InterestDuplicateGuard::class, $guard);

    $service = app(InterestService::class);

    $service->send($this->investor, $this->project, ['interest_type' => 'investment']);

    // المحاولة الثانية → QueryException 23000 → DuplicateInterestException (422).
    expect(fn () => $service->send($this->investor, $this->project, ['interest_type' => 'investment']))
        ->toThrow(DuplicateInterestException::class);

    // سجل واحد فقط — لا تكرار.
    expect(
        Interest::where('project_id', $this->project->id)
            ->where('investor_id', $this->investor->id)
            ->count()
    )->toBe(1);
});
