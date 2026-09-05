<?php

namespace Tests\Unit\Trash;

use App\Enums\FileType;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * projects:purge-trash — T070 · US-055 (trash-api.md §4).
 *
 * الحذف النهائي بعد 30 يوماً · --dry-run يعرض بلا حذف · حذف ملفات القرص ·
 * سجل تدقيق · لا يمس المشاريع داخل المهلة.
 */

beforeEach(function () {
    config(['scout.driver' => 'null']);
    Storage::fake('public');
    $this->owner = User::factory()->ideaOwner()->create();
});

it('purges only projects whose 30-day window has lapsed', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');

    $expired = Project::factory()->create(['user_id' => $this->owner->id, 'deleted_at' => now()->subDays(31)]);
    $fresh = Project::factory()->create(['user_id' => $this->owner->id, 'deleted_at' => now()->subDays(5)]);

    $this->artisan('projects:purge-trash')
        ->expectsOutputToContain('Purged 1 trashed project(s).')
        ->assertSuccessful();

    $this->assertDatabaseMissing('projects', ['id' => $expired->id]);
    $this->assertSoftDeleted('projects', ['id' => $fresh->id]);
});

it('deletes the disk files of purged projects', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');

    $expired = Project::factory()->create(['user_id' => $this->owner->id, 'deleted_at' => now()->subDays(31)]);
    Storage::disk('public')->put('files/old.png', 'content');
    ProjectFile::create([
        'project_id' => $expired->id,
        'type' => FileType::IMAGE,
        'file_path' => 'files/old.png',
        'original_name' => 'old.png',
        'mime_type' => 'image/png',
        'file_size' => 7,
    ]);

    $this->artisan('projects:purge-trash')->assertSuccessful();

    $this->assertDatabaseMissing('projects', ['id' => $expired->id]);
    Storage::disk('public')->assertMissing('files/old.png');
});

it('dry-run reports what would be purged without deleting anything', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');

    $expired = Project::factory()->create(['user_id' => $this->owner->id, 'deleted_at' => now()->subDays(31)]);

    $this->artisan('projects:purge-trash', ['--dry-run' => true])
        ->expectsOutputToContain('[dry-run] 1 trashed project(s) would be purged.')
        ->assertSuccessful();

    // لا شيء حُذف.
    $this->assertSoftDeleted('projects', ['id' => $expired->id]);
});

it('writes an audit log entry for each purged project', function () {
    Log::spy();
    Carbon::setTestNow('2026-08-01 12:00:00');

    Project::factory()->create(['user_id' => $this->owner->id, 'deleted_at' => now()->subDays(31)]);

    $this->artisan('projects:purge-trash')->assertSuccessful();

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $channel, array $ctx) => $channel === 'trash.purged')
        ->once();
});

it('does nothing when no project has lapsed', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');
    Project::factory()->create(['user_id' => $this->owner->id, 'deleted_at' => now()->subDays(5)]);

    $this->artisan('projects:purge-trash')
        ->expectsOutputToContain('Purged 0 trashed project(s).')
        ->assertSuccessful();

    $this->assertSoftDeleted('projects', ['id' => Project::withTrashed()->first()->id]);
});
