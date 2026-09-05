<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * الملف الشخصي الموسّع — جدول user_profiles (1:1 مع users) — T165.
 *
 * القرار (T165): تُنشأ هنا علاقات الجدول، لكن منطق التشغيل الحالي (Sprint 1)
 * يبقى على أعمدة جدول users المكررة (bio, avatar_path, university ...) — لا ننقل
 * البيانات الآن حتى لا يكسر تدفق Auth/Profile (مبدأ 1: لا كسر للاختبارات).
 * الترحيل إلى هذا الجدول مُخطط لاحقاً (Sprint 4+ / ما بعد MVP).
 */
class UserProfile extends Model
{
    protected $fillable = [
        'user_id',
        'bio',
        'avatar_url',
        'skills',
        'social_links',
        'university',
        'major',
        'investment_focus',
        'investment_range',
        'preferred_sectors',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'social_links' => 'array',
            'investment_range' => 'array',
            'preferred_sectors' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
