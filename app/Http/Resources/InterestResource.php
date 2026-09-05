<?php

namespace App\Http\Resources;

use App\Enums\InterestStatus;
use App\Models\Interest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * T035 · T054 — مورد طلب الاهتمام (US-041..046 · contract §2/§3/§5).
 *
 * كشف البريد المتبادل فقط بعد القبول (status === accepted) وللطرف فقط
 * (المرسل أو مالك المشروع — دستور §I: لا كشف مبكر؛ §V: أمن). عدا ذلك
 * `emails` كائن فارغ `{}`.
 *
 * can_cancel: true للمستثمر المرسل عندما يكون الطلب نشطاً (pending/accepted/
 * accepted_pending_document) — لإظهار زر الإلغاء في لوحة المرسلة (UC-12).
 */
class InterestResource extends JsonResource
{
    /** @var Interest */
    public $resource;

    public function toArray(Request $request): array
    {
        $user = $request->user('sanctum');

        $data = [
            'id' => $this->id,
            'project' => $this->project ? ProjectResource::make($this->project)->card($user) : null,
            'investor' => [
                'id' => $this->investor?->id,
                'name' => $this->investor?->name,
                'avatar_url' => $this->investor?->avatar_path
                    ? asset('storage/'.$this->investor->avatar_path)
                    : null,
            ],
            'interest_type' => $this->interest_type->value,
            'message' => $this->message,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'rejection_reason' => $this->rejection_reason,
            'agreement_id' => $this->agreement_id,
            'agreement' => $this->whenLoaded('agreement') && $this->agreement ? [
                'id' => $this->agreement->id,
                'pdf_url' => '/api/agreements/'.$this->agreement->id,
                'idea_owner_name' => $this->agreement->idea_owner_name,
                'investor_name' => $this->agreement->investor_name,
                'created_at' => $this->agreement->created_at?->toISOString(),
            ] : null,
            'accepted_at' => $this->accepted_at?->toISOString(),
            'rejected_at' => $this->rejected_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            // كشف بعد القبول + للطرف فقط (دستور §I/§V) — إلا { } فارغ.
            'emails' => (object) [],
        ];

        if ($this->status === InterestStatus::ACCEPTED && $this->isParty($user)) {
            $data['emails'] = [
                'investor_email' => $this->investor?->email,
                'idea_owner_email' => $this->project?->owner?->email,
            ];
        }

        // لوحة المرسلة: زر الإلغاء للطلبات النشطة (المستثمر المرسل فقط).
        if ($user && (int) $user->id === (int) $this->investor_id) {
            $data['can_cancel'] = $this->status->isActive();
        }

        return $data;
    }
}
