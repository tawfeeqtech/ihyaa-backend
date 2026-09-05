<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * الملف العام (L2 — SRS-API-12) — T161 (بدل ProfileController::showPublic).
 * لا بريد ولا بيانات حساسة — حقول حسب الدور:
 *  - صاحب فكرة: university + major
 *  - مستثمر:    investment_focus + preferred_sectors
 */
class ProfileResource extends JsonResource
{
    /** @var User */
    public $resource;

    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->role?->value,
            'avatar_url' => $this->avatar_path ? asset('storage/'.$this->avatar_path) : null,
            'bio' => $this->bio,
        ];

        if ($this->isIdeaOwner()) {
            $data['university'] = $this->university;
            $data['major'] = $this->major;
        }

        if ($this->isInvestor()) {
            $data['investment_focus'] = $this->investment_focus;
            $data['preferred_sectors'] = $this->preferred_sectors;
        }

        return $data;
    }
}
