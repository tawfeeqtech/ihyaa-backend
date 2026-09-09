<?php

namespace App\Models;

use App\Support\DeviceDetector;
use App\Support\GeoLocationService;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * الحقول القابلة للتعبئة.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'last_used_at',
        'ip_address',
        'user_agent',
        'device_name',
        'location',
    ];

    /**
     * تحويل التوكن إلى مصفوفة بيانات الجلسة للفرونت إند.
     */
    public function toSessionArray(?self $currentToken = null): array
    {
        $deviceInfo = DeviceDetector::detect($this->user_agent, $this->device_name);
        $lastActive = $this->last_used_at ?? $this->created_at;
        $isCurrent = $currentToken !== null && (int) $this->id === (int) $currentToken->id;
        $isActive = $this->expires_at === null || $this->expires_at->isFuture();

        return [
            'id' => $this->id,
            'is_current' => $isCurrent,
            'is_active' => $isActive,
            'device' => [
                'name' => $deviceInfo['device_name'],
                'type' => $deviceInfo['device_type'],
            ],
            'os' => $deviceInfo['os'],
            'browser' => $deviceInfo['browser'],
            'location' => $this->location ?: GeoLocationService::DEFAULT_LOCATION,
            'ip_address' => $this->ip_address ?: '127.0.0.1',
            'last_active_at' => $lastActive?->toISOString(),
            'last_active_human' => $lastActive ? $lastActive->locale('ar')->diffForHumans() : 'الآن',
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
