<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * مورد المستخدم (ملف كامل خاص) — T161 (بدل User::toApiArray — SRS-API-09..11).
 * يُستخدم للاستجابات الموثَّقة: AuthController + ProfileController show/update.
 * للعرض العام (بدون بريد) استخدم ProfileResource.
 */
class UserResource extends JsonResource
{
    /** @var User */
    public $resource;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role?->value,
            'email_verified' => $this->email_verified_at !== null,
            'avatar_url' => $this->avatar_path ? asset('storage/'.$this->avatar_path) : null,
            'bio' => $this->bio,
            'university' => $this->university,
            'major' => $this->major,
            'investment_focus' => $this->investment_focus,
            'investment_range' => $this->investment_range,
            'preferred_sectors' => $this->preferred_sectors,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
