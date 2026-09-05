<?php

namespace Tests\Unit\Dashboard;

use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Services\Dashboard\OwnerEventsFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * OwnerEventsFeedService — T060 · US-053 (dashboard-api.md §1.feed).
 *
 * آخر 10 أحداث من جدول notifications (مصدر الحقيقة الوحيد)، مع ربط الأنواع
 * (interest_new → interest_received ...) وربط المشروع المرتبط دفعة واحدة.
 */

/** ينشئ إشعاراً بتاريخ قطعي. */
function feedEvent(User $user, string $type, string $createdAt, array $data = []): Notification
{
    $n = Notification::create([
        'user_id' => $user->id,
        'type' => $type,
        'title' => 'عنوان '.$type,
        'body' => 'نص تجريبي',
        'data' => $data,
        'is_critical' => in_array($type, ['interest_new', 'evaluation_completed'], true),
    ]);

    $n->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

    return $n;
}

beforeEach(function () {
    config(['scout.driver' => 'null']);
    $this->feed = app(OwnerEventsFeedService::class);
    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);
});

it('returns the latest 10 events descending with has_more and next_cursor', function () {
    foreach (range(1, 12) as $i) {
        feedEvent($this->owner, 'interest_new', '2026-08-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).' 10:00:00', ['project_id' => $this->project->id]);
    }

    $feed = $this->feed->recentFor($this->owner);

    expect(count($feed['items']))->toBe(10);
    expect($feed['has_more'])->toBeTrue();
    // next_cursor = معرّف أقدم حدث معروض (للمتابعة عبر /api/notifications).
    expect($feed['next_cursor'])->toBe($feed['items'][9]['id']);
    // الأحدث أولاً — الإشعار الثاني عشر في المقدمة.
    expect($feed['items'][0]['type'])->toBe('interest_received');
});

it('does not claim more events when exactly 10 exist', function () {
    foreach (range(1, 10) as $i) {
        feedEvent($this->owner, 'interest_new', '2026-08-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).' 10:00:00');
    }

    $feed = $this->feed->recentFor($this->owner);

    expect(count($feed['items']))->toBe(10);
    expect($feed['has_more'])->toBeFalse();
    expect($feed['next_cursor'])->toBe($feed['items'][9]['id']);
});

it('maps the stored notification types to feed event types', function () {
    feedEvent($this->owner, 'interest_new', '2026-08-01 10:00:00');
    feedEvent($this->owner, 'interest_accepted', '2026-08-02 10:00:00');
    feedEvent($this->owner, 'interest_rejected', '2026-08-03 10:00:00');
    feedEvent($this->owner, 'interest_cancelled', '2026-08-04 10:00:00');
    feedEvent($this->owner, 'evaluation_completed', '2026-08-05 10:00:00');
    feedEvent($this->owner, 'evaluation_failed', '2026-08-06 10:00:00');
    feedEvent($this->owner, 'project_updated', '2026-08-07 10:00:00');
    feedEvent($this->owner, 'project_trashed', '2026-08-08 10:00:00');

    $types = collect($this->feed->recentFor($this->owner)['items'])->map->type->values()->all();

    expect($types)->toBe([
        'project_trashed',
        'project_edited',
        'evaluation_failed',
        'evaluation_completed',
        'interest_cancelled',
        'interest_rejected',
        'interest_accepted',
        'interest_received',
    ]);
});

it('links related_project and action_url from notification data', function () {
    feedEvent($this->owner, 'interest_new', '2026-08-01 10:00:00', [
        'project_id' => $this->project->id,
        'url' => '/projects/'.$this->project->id,
    ]);

    $item = $this->feed->recentFor($this->owner)['items'][0];

    expect($item['related_project'])->toBe(['id' => $this->project->id, 'title' => $this->project->title]);
    expect($item['action_url'])->toBe('/projects/'.$this->project->id);
    expect($item['is_critical'])->toBeTrue();
});

it('resolves the related project even when it was later trashed', function () {
    feedEvent($this->owner, 'interest_new', '2026-08-01 10:00:00', ['project_id' => $this->project->id]);
    $this->project->delete();

    $item = $this->feed->recentFor($this->owner)['items'][0];

    expect($item['related_project']['title'])->toBe($this->project->title);
});

it('returns a null related_project when the event has no project_id', function () {
    feedEvent($this->owner, 'interest_accepted', '2026-08-01 10:00:00');

    $item = $this->feed->recentFor($this->owner)['items'][0];

    expect($item['related_project'])->toBeNull();
});
