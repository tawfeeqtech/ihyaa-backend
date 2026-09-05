<?php

namespace App\Http\Resources\Dashboard;

use App\Models\Notification;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * حدث تغذية اللوحة (T027 · US-053) — dashboard-api.md §1.feed.
 *
 * يبني حدثاً واحداً من إشعار مخزَّن. خريطة النوع يطبقها OwnerEventsFeedService؛
 * هنا يُمرَّر النوع المعروض (type) جاهزاً، مع ربط المشروع المرتبط (من data.project_id)
 * عبر خريطة تُمرَّر دفعة واحدة (لا N+1 — تحميل في الخدمة).
 *
 * يمكن تمرير `projectsById` كـ additional: [FeedEventResource::KEY => [...]].
 */
class FeedEventResource extends JsonResource
{
    public const KEY = '_feed_projects';

    /** @var Notification */
    public $resource;

    public function toArray(Request $request): array
    {
        $n = $this->resource;
        $data = is_array($n->data) ? $n->data : (json_decode((string) $n->data, true) ?? []);

        $projectId = isset($data['project_id']) ? (int) $data['project_id'] : null;
        $project = null;
        if ($projectId !== null) {
            $projects = $request->attributes->get(self::KEY, []);
            $project = $projects[$projectId] ?? null;
        }

        return [
            'id' => $n->id,
            'type' => $data['_feed_type'] ?? $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'is_critical' => (bool) $n->is_critical,
            'read_at' => $n->read_at?->toISOString(),
            'related_project' => $project instanceof Project
                ? ['id' => $project->id, 'title' => $project->title]
                : null,
            'created_at' => $n->created_at?->toISOString(),
            'action_url' => $data['url'] ?? null,
        ];
    }
}
