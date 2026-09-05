<?php

namespace Tests\Unit\Trash;

use App\Enums\FileType;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\User;
use App\Services\Project\TrashService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * TrashService — T070 · US-055 (trash-api.md §1..3).
 *
 * البطاقة: purge_at + days_remaining (يمكن أن يكون سالباً بعد المهلة) + restorable.
 * الاسترجاع: TOCTOU guard + رفض TRASH_EXPIRED بعد 30 يوماً.
 * الحذف النهائي: يمسح ملفات القرص ثم المشروع + سجل تدقيق.
 */

beforeEach(function () {
    config(['scout.driver' => 'null']);
    $this->service = app(TrashService::class);
    $this->owner = User::factory()->ideaOwner()->create();
});

it('builds a trash card with purge_at, days_remaining and restorable', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');
    $project = Project::factory()->create(['user_id' => $this->owner->id, 'deleted_at' => now()]);

    $card = $this->service->card($project);

    expect($card['purge_at'])->toBe('2026-08-31T12:00:00.000000Z');
    expect($card['restore_deadline'])->toBe($card['purge_at']); // توافق خلفي — نفس القيمة
    expect($card['days_remaining'])->toBe(30);
    expect($card['restorable'])->toBeTrue();
});

it('marks the card non-restorable with negative days when the window lapsed', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');
    $project = Project::factory()->create(['user_id' => $this->owner->id, 'deleted_at' => now()]);

    // بعد 31 يوماً — أمر التنظيف لم ينفَّذ بعد لكن المهلة انتهت.
    Carbon::setTestNow('2026-09-01 12:00:00');

    $card = $this->service->card($project);

    expect($card['days_remaining'])->toBe(-1);
    expect($card['restorable'])->toBeFalse();
});

it('restores a trashed project within the recovery window', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');
    $project = Project::factory()->create(['user_id' => $this->owner->id, 'deleted_at' => now()->subDays(5)]);

    $this->service->restore($project);

    expect($project->fresh()->trashed())->toBeFalse();
});

it('throws PROJECT_NOT_TRASHED when restoring a live project', function () {
    $project = Project::factory()->create(['user_id' => $this->owner->id]);

    $this->service->restore($project);
})->throws(DomainException::class, 'PROJECT_NOT_TRASHED');

it('throws TRASH_EXPIRED when restoring beyond the 30-day window', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');
    $project = Project::factory()->create(['user_id' => $this->owner->id, 'deleted_at' => now()->subDays(31)]);

    Carbon::setTestNow('2026-09-01 12:00:00');

    $this->service->restore($project);
})->throws(DomainException::class, 'TRASH_EXPIRED');

it('force deletes a trashed project and its disk files', function () {
    Storage::fake('public');
    $project = Project::factory()->create(['user_id' => $this->owner->id, 'deleted_at' => now()]);

    Storage::disk('public')->put('files/photo.png', 'content');
    ProjectFile::create([
        'project_id' => $project->id,
        'type' => FileType::IMAGE,
        'file_path' => 'files/photo.png',
        'original_name' => 'photo.png',
        'mime_type' => 'image/png',
        'file_size' => 7,
    ]);

    $this->service->forceDelete($project);

    $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    $this->assertDatabaseMissing('project_files', ['project_id' => $project->id]);
    Storage::disk('public')->assertMissing('files/photo.png');
});

it('writes an audit log entry on force delete (trash-api.md §4)', function () {
    Storage::fake('public');
    Log::spy();
    $project = Project::factory()->create(['user_id' => $this->owner->id, 'deleted_at' => now()]);

    $this->service->forceDelete($project);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $channel, array $ctx) => $channel === 'trash.force_deleted')
        ->once();
});

it('throws PROJECT_NOT_TRASHED when force deleting a live project', function () {
    $project = Project::factory()->create(['user_id' => $this->owner->id]);

    $this->service->forceDelete($project);
})->throws(DomainException::class, 'PROJECT_NOT_TRASHED');
