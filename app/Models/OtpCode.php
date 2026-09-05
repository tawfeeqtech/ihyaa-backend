<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رمز OTP — جدول otp_codes (إصلاح أمني: bcrypt hash منفصل عن users) — T165.
 *
 * القرار (T165): تُنشأ هنا علاقات الجدول، لكن منطق التشغيل الحالي (Sprint 1)
 * يبقى على أعمدة جدول users (otp_code bcrypt، otp_expires_at ...) عبر
 * User::generateOtp/verifyOtpCode — لا ننقل الآن حتى لا يكسر Auth (مبدأ 1).
 * الترحيل إلى هذا الجدول مُخطط لاحقاً (إعادة إرسال + Password Reset).
 */
class OtpCode extends Model
{
    protected $fillable = [
        'user_id',
        'code_hash',
        'purpose',
        'expires_at',
        'used_at',
        'invalidated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** رمز حي (غير مستخدم وغير مُبطل وغير منتهٍ) — لمستخدم بغرض معيّن */
    public function scopeActive($query, string $purpose): Builder
    {
        return $query
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now());
    }
}
