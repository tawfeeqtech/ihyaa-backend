<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Http\Resources\UserResource;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const OTP_EXPIRY_SECONDS = 60;   // صلاحية رمز OTP: دقيقة واحدة (SRS-F01-02)

    public const OTP_MAX_ATTEMPTS = 3;      // حظر بعد 3 محاولات خاطئة

    public const OTP_RESEND_LIMIT = 3;      // حد الإعادة: 3/دقيقة (rate limit)

    public const TOKEN_EXPIRY_HOURS = 24;   // صلاحية توكن Sanctum (SRS-NFR-07)

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'provider',
        'provider_id',
        'otp_code',
        'otp_expires_at',
        'otp_attempts',
        'otp_last_sent_at',
        'avatar_path',
        'bio',
        'university',
        'major',
        'investment_focus',
        'investment_range',
        'preferred_sectors',
        'is_active',
        'email_verified_at',
        'last_login_at',
        'last_active_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'otp_code',
        'otp_expires_at',
        'otp_attempts',
        'otp_last_sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'investment_range' => 'array',
            'preferred_sectors' => 'array',
            'otp_expires_at' => 'datetime',
            'otp_last_sent_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_active_at' => 'datetime',
        ];
    }

    // ——————————————————————— العلاقات ———————————————————————

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** طلبات الاهتمام المرسلة (بصفتي مستثمراً) */
    public function interestsSent(): HasMany
    {
        return $this->hasMany(Interest::class, 'investor_id');
    }

    /** طلبات الاهتمام المستلمة (عبر مشاريعي — بصفتي صاحب فكرة) */
    public function interestsReceived(): HasManyThrough
    {
        return $this->hasManyThrough(Interest::class, Project::class, 'user_id', 'project_id');
    }

    public function savedProjects(): HasMany
    {
        return $this->hasMany(SavedProject::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Pivot role_user — توسعة مقبولة من Sprint 1 (توثيق T168).
     * عمود `role` على جدول users هو المصدر الأساسي لدور المستخدم (تستخدمه الـ Middleware
     * وـ Rate Limiters)، والـ pivot مرجعي يُزامن عبر User::setRole (SRS-F01-07).
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    /** الملف الموسّع (user_profiles — 1:1) — T165: الجدول منشأ، النقل لاحقاً */
    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    /** رموز OTP (otp_codes — 1:N) — T165: الجدول منشأ، النقل لاحقاً */
    public function otpCodes(): HasMany
    {
        return $this->hasMany(OtpCode::class);
    }

    // ——————————————————————— الدور ———————————————————————

    public function isIdeaOwner(): bool
    {
        return $this->role === UserRole::IDEA_OWNER;
    }

    public function isInvestor(): bool
    {
        return $this->role === UserRole::INVESTOR;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::ADMIN;
    }

    public function hasRole(UserRole $role): bool
    {
        return $this->role === $role;
    }

    /**
     * تعيين الدور — مرة واحدة فقط من API عندما يكون null (أول دخول OAuth — SRS-F01-07).
     * يزامن pivot role_user (البحث بالاسم — لا IDs صلبة).
     */
    public function setRole(UserRole $role): void
    {
        $this->forceFill(['role' => $role])->save();

        $roleModel = Role::where('name', $role->value)->first();

        if ($roleModel) {
            $this->roles()->sync([$roleModel->id]);
        }
    }

    // ——————————————————————— OTP (6 أرقام — دقيقة واحدة) ———————————————————————

    /**
     * إنشاء رمز OTP جديد (صلاحية 60 ثانية) وإرساله بالبريد.
     * الرمز يُخزَّن مشفَّراً (bcrypt) — لم يعد plaintext في قاعدة البيانات.
     * plaintext يُرسل بالبريد فقط ولا يُحفظ.
     */
    public function generateOtp(): string
    {
        $code = (string) random_int(100000, 999999);

        $this->forceFill([
            'otp_code' => Hash::make($code),
            'otp_expires_at' => now()->addSeconds(self::OTP_EXPIRY_SECONDS),
            'otp_attempts' => 0,
            'otp_last_sent_at' => now(),
        ])->save();

        $this->sendOtpEmail($code);

        // تجربة التطوير: إظهار الرمز في السجل لتسهيل الاستخدام المحلي.
        // يُفعَّل في بيئة DEBUG أو عند MAIL_MAILER=log (البريد يذهب للسجل أصلاً —
        // لكن سطر مباشر أوضح للبحث): "Ihyaa OTP for {email}: {code}".
        if (config('app.debug') || config('mail.default') === 'log') {
            Log::info("Ihyaa OTP for {$this->email}: {$code}");
        }

        return $code;
    }

    /**
     * التحقق من رمز OTP.
     * النجاح: يُصرف الرمز فوراً (set null) + تفعيل البريد (SRS-F01-02).
     */
    public function verifyOtpCode(string $code): bool
    {
        if ($this->otp_attempts >= self::OTP_MAX_ATTEMPTS || $this->otp_code === null) {
            return false;
        }

        if ($this->otp_expires_at === null || $this->otp_expires_at->isPast()) {
            return false;
        }

        // OTP مخزّن ببصمة bcrypt — التحقق يتم عبر Hash::check وليس مقارنة plaintext
        if (! Hash::check($code, (string) $this->otp_code)) {
            $this->increment('otp_attempts');

            return false;
        }

        $this->forceFill([
            'otp_code' => null,
            'otp_expires_at' => null,
            'otp_attempts' => 0,
            'email_verified_at' => now(),
        ])->save();

        return true;
    }

    public function otpIsBlocked(): bool
    {
        return $this->otp_attempts >= self::OTP_MAX_ATTEMPTS;
    }

    public function otpIsExpired(): bool
    {
        return $this->otp_expires_at === null || $this->otp_expires_at->isPast();
    }

    public function sendOtpEmail(string $code): void
    {
        Mail::raw(
            "رمز التحقق الخاص بك في منصة إحياء هو: {$code}\n".
            'الرمز صالح لمدة دقيقة واحدة. إذا لم تكن أنت من طلب هذا الرمز، تجاهل هذه الرسالة.',
            function ($message) {
                $message->to($this->email)
                    ->subject('رمز التحقق — منصة إحياء (Ihyaa)');
            }
        );
    }

    // ——————————————————————— أدوات ———————————————————————

    /** تمثيل الرمز للاستجابة الموحّدة (دون بيانات حساسة). T161: التفويض إلى UserResource. */
    public function toApiArray(): array
    {
        return UserResource::make($this)->resolve();
    }
}
