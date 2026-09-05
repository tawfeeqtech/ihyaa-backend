<?php

namespace App\Services\Dashboard;

use App\Models\Notification;
use App\Models\Project;
use App\Models\User;

/**
 * تغذية أحداث لوحة صاحب الفكرة (US-053 · T062) — dashboard-api.md §1.feed.
 *
 * آخر 10 أحداث من جدول notifications (مصدر الحقيقة الوحيد — لا حالة منفصلة):
 *  - interest_received (interest_new) · interest_accepted · interest_rejected
 *  - evaluation_completed (completed/partial) · evaluation_failed
 *  - project_edited (project_updated) · project_trashed
 *
 * التغليف: { items, has_more, next_cursor } — الترحيل الكامل للأحداث عبر
 * GET /api/notifications (EPIC-09 · offset/20 للصفحة).
 */
class OwnerEventsFeedService
{
    /** عدد الأحداث على اللوحة — dashboard-api.md §1 (آخر 10). */
    public const LIMIT = 10;

    /** ربط نوع الإشعار المخزَّن بنوع حدث اللوحة. */
    private const TYPE_MAP = [
        'interest_new' => 'interest_received',
        'interest_accepted' => 'interest_accepted',
        'interest_rejected' => 'interest_rejected',
        'interest_cancelled' => 'interest_cancelled',
        'evaluation_completed' => 'evaluation_completed',
        'evaluation_partial' => 'evaluation_completed',
        'evaluation_failed' => 'evaluation_failed',
        'project_updated' => 'project_edited',
        'project_trashed' => 'project_trashed',
        'analysis_completed' => 'analysis_completed',
        'pdf_generation_failed' => 'evaluation_failed',
    ];

    public function recentFor(User $user): array
    {
        $notifications = $user->notifications()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT + 1)
            ->get();

        $hasMore = $notifications->count() > self::LIMIT;
        $items = $notifications->take(self::LIMIT);

        $projects = $this->loadRelatedProjects($items);

        return [
            'items' => $items
                ->map(fn (Notification $n) => $this->mapItem($n, $projects))
                ->values(),
            'has_more' => $hasMore,
            'next_cursor' => $items->last()?->id,
        ];
    }

    /** تحويل إشعار واحد إلى حدث — مع ربط المشروع المرتبط (من data.project_id). */
    private function mapItem(Notification $n, array $projects): array
    {
        $data = is_array($n->data) ? $n->data : (json_decode((string) $n->data, true) ?? []);

        $projectId = isset($data['project_id']) ? (int) $data['project_id'] : null;
        $project = $projectId !== null ? ($projects[$projectId] ?? null) : null;

        return [
            'id' => $n->id,
            'type' => self::TYPE_MAP[$n->type] ?? $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'is_critical' => (bool) $n->is_critical,
            'read_at' => $n->read_at?->toISOString(),
            'related_project' => $project !== null
                ? ['id' => $project->id, 'title' => $project->title]
                : null,
            'created_at' => $n->created_at?->toISOString(),
            'action_url' => $data['url'] ?? null,
        ];
    }

    /** تحميل المشاريع المرتبطة دفعة واحدة (لا N+1) — مع المهملات (استمرار عرض العنوان). */
    private function loadRelatedProjects($items): array
    {
        $ids = collect($items)
            ->map(fn (Notification $n) => is_array($n->data) ? ($n->data['project_id'] ?? null) : null)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return Project::query()
            ->withTrashed()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id')
            ->all();
    }
}
