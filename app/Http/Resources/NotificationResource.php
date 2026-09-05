<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * مورد الإشعارات — T066 · EPIC-09 (US-047).
 *
 * الحمولة الموحّدة للجرس والقائمة والصفحة الكاملة والبث الحي:
 *   id, type, title, body, data (مع url للكيان المرتبط), is_critical,
 *   read_at, created_at, created_at_relative (عربي/إنجليزي حسب لغة الطلب).
 *
 * `url` يُستخرج من data للتنقل المباشر عند النقر (T069) ويظهر أيضاً في الحمولة
 * العلوية للراحة — نفس القيمة، لا ازدواجية.
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_array($this->data) ? $this->data : (json_decode((string) $this->data, true) ?? []);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'data' => $data,
            'is_critical' => (bool) $this->is_critical,
            'read_at' => $this->read_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'created_at_relative' => $this->created_at?->diffForHumans(),
            'url' => $data['url'] ?? null,
        ];
    }
}
