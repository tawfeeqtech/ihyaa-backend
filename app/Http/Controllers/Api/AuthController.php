<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Socialite\Facades\Socialite;

/**
 * المصادقة — SRS-API-01..08 · SRS-F01.
 * OTP: 6 أرقام — صلاحية دقيقة واحدة · حظر بعد 3 محاولات · إعادة الإرسال 3/دقيقة (Rate Limit).
 * توكن Sanctum: 24 ساعة (SRS-NFR-07).
 */
class AuthController
{
    use ApiResponse;

    // ——————————————————————— التسجيل (RL-AUTH-01 · 3/دقيقة) ———————————————————————

    public function register(RegisterRequest $request): JsonResponse
    {
        // T163: القواعد في RegisterRequest — نقل من الـ controller بلا تغيير في السلوك.
        $data = $request->validated();

        $role = UserRole::from($data['role']);

        // admin لا يُنشأ بالتسجيل العام (SRS §1.2)
        if ($role->isAdmin()) {
            return $this->error('FORBIDDEN', __('auth.admin_registration_forbidden'), 403);
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $role,
            'university' => $data['university'] ?? null,
            'major' => $data['major'] ?? null,
            'investment_focus' => $data['investment_focus'] ?? null,
            'investment_range' => $data['investment_range'] ?? null,
            'preferred_sectors' => $data['preferred_sectors'] ?? null,
        ]);

        $roleModel = Role::where('name', $role->value)->first();

        if ($roleModel) {
            $user->roles()->attach($roleModel->id);
        }

        // إشعار ترحيبي فوري (T144) — في التطبيق فقط، بلا بريد (الدستور C11).
        Notification::pushNotification(
            $user->id,
            'welcome',
            __('notifications.welcome_title'),
            __('notifications.welcome_body'),
            ['url' => '/dashboard'],
        );

        // OTP تفعيل البريد — إلزامي (SRS-F01-02)
        $otp = $user->generateOtp();

        // الدستور V · US-001 s6: لا يُصدر توكن قبل تفعيل البريد (T124).
        // التوكن يُصدر بعد التفعيل الناجح فقط — في verifyEmail() أو login().
        $response = ['otp_required' => true];

        // تجربة التطوير: إظهار الرمز في استجابة register عند APP_DEBUG=true فقط
        // (لا يُكشف في الإنتاج). الرمز يُنشأ هنا ويُرسل بالبريد — هذه نسخة مساعدة.
        if (config('app.debug')) {
            $response['dev_otp'] = $otp;
        }

        return $this->created($response, __('auth.registered'));
    }

    // ——————————————————————— الدخول (RL-AUTH-02 · 5/دقيقة لكل بريد) ———————————————————————

    public function login(LoginRequest $request): JsonResponse
    {
        // T163: القواعد في LoginRequest — نقل من الـ controller بلا تغيير في السلوك.
        $data = $request->validated();

        $user = User::where('email', $data['email'])->first();

        // حساب غير مفعَّل البريد → رفض الدخول قبل محاولة المصادقة (US-003 س4 · SRS-F01-02)
        if ($user && $user->email_verified_at === null) {
            return $this->error('EMAIL_NOT_VERIFIED', 'يرجى تفعيل بريدك الإلكتروني أولاً', 401);
        }

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return $this->error('INVALID_CREDENTIALS', __('auth.invalid_credentials'), 401);
        }

        if (! $user->is_active) {
            return $this->error('ACCOUNT_DISABLED', __('auth.account_disabled'), 403);
        }

        $user->forceFill(['last_login_at' => now(), 'last_active_at' => now()])->save();

        $token = $user->createToken('api', ['*'], now()->addHours(User::TOKEN_EXPIRY_HOURS));

        return $this->success([
            'token' => $token->plainTextToken,
            'token_expires_at' => now()->addHours(User::TOKEN_EXPIRY_HOURS)->toISOString(),
            'user' => $user->toApiArray(),
        ], __('auth.logged_in'));
    }

    // ——————————————————————— الخروج (RL-AUTH-03 · 10/دقيقة) ———————————————————————

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->noContent(__('auth.logged_out'));
    }

    // ——————————————————————— المستخدم الحالي ———————————————————————

    public function me(Request $request): JsonResponse
    {
        return $this->success($request->user()->toApiArray());
    }

    // ——————————————————————— التحقق من البريد OTP (RL-AUTH-04 · 3/دقيقة) ———————————————————————

    /**
     * POST /email/verify — {email, code?}
     * code غائب = إعادة إرسال رمز جديد (UC-01 A2).
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
            'code' => ['nullable', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            return $this->notFound(__('auth.user_not_found'));
        }

        // إعادة إرسال
        if (empty($data['code'])) {
            return $this->resendOtp($request);
        }

        if ($user->email_verified_at !== null) {
            return $this->success(['verified' => true], __('auth.already_verified'));
        }

        if ($user->otpIsBlocked()) {
            return $this->error('OTP_BLOCKED', __('auth.otp_blocked'), 429);
        }

        if ($user->otpIsExpired()) {
            return $this->error('OTP_EXPIRED', __('auth.otp_expired'), 422);
        }

        if (! $user->verifyOtpCode($data['code'])) {
            return $this->error('OTP_INVALID', __('auth.otp_invalid'), 422);
        }

        // الدستور V · US-001 s6: لحظة التفعيل الناجح — هنا يُصدر التوكن لأول مرة (T124).
        $token = $user->createToken('api', ['*'], now()->addHours(User::TOKEN_EXPIRY_HOURS));

        return $this->success([
            'token' => $token->plainTextToken,
            'token_expires_at' => now()->addHours(User::TOKEN_EXPIRY_HOURS)->toISOString(),
            'user' => $user->toApiArray(),
        ], __('auth.otp_verified'));
    }

    /** إعادة إرسال رمز OTP — الحد 3/دقيقة عبر throttle:api.otp */
    public function resendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            return $this->notFound(__('auth.user_not_found'));
        }

        if ($user->email_verified_at !== null) {
            return $this->success(['verified' => true], __('auth.already_verified'));
        }

        if ($user->otpIsBlocked()) {
            return $this->error('OTP_BLOCKED', __('auth.otp_blocked'), 429);
        }

        $user->generateOtp();

        return $this->success(['otp_sent' => true], __('auth.otp_sent'));
    }

    // ——————————————————————— OAuth (SRS-F01-07 · RL-AUTH-07/08 · 5/دقيقة) ———————————————————————

    /**
     * تحويل اسم المزوّد من الرابط العام إلى اسم Socialite الداخلي.
     * LinkedIn الجديد يستخدم OpenID Connect ← driver اسمه linkedin-openid.
     */
    private function resolveProvider(string $provider): string
    {
        return match ($provider) {
            'linkedin' => 'linkedin-openid',
            default    => $provider, // google, github
        };
    }

    public function redirectToProvider(string $provider, Request $request): JsonResponse
    {
        // CSRF state — يُخزَّن في Redis بصلاحية 10 دقائق ويُستهلك في callback (استهلاك لمرة واحدة)
        $state = Str::random(40);

        // Store the frontend redirect URL alongside the provider name so the
        // callback can redirect the browser back to the SPA with the token.
        $payload = json_encode([
            'provider' => $provider,
            'redirect_to' => $request->input('redirect_to', config('app.frontend_url').'/ar/auth/callback'),
        ]);

        Redis::setex('oauth_state:'.$state, 600, $payload);

        $url = Socialite::driver($this->resolveProvider($provider))
            ->stateless()
            ->with(['state' => $state])
            ->redirect()
            ->getTargetUrl();

        return $this->success(['redirect_url' => $url], __('auth.oauth_redirect'));
    }

    public function handleProviderCallback(string $provider, Request $request): \Illuminate\Http\RedirectResponse|JsonResponse
    {
        // فحص state — يمنع تزوير إعادة التوجيه (CSRF). المفتاح يُستهلك من Redis مرة واحدة.
        $state = $request->input('state');

        // Resolve the frontend redirect URL from Redis (stored by redirectToProvider).
        $storedRaw = $state ? Redis::get('oauth_state:'.$state) : null;
        $stored = $storedRaw ? json_decode($storedRaw, true) : null;
        $redirectTo = $stored['redirect_to'] ?? config('app.frontend_url').'/ar/auth/callback';

        if ($state === null || $stored === null) {
            return $this->redirectWithError($redirectTo, 'INVALID_STATE', __('auth.oauth_invalid_state'));
        }

        // استهلاك لمرة واحدة — لا يمكن إعادة استخدام نفس state
        Redis::del('oauth_state:'.$state);

        if (($stored['provider'] ?? '') !== $provider) {
            return $this->redirectWithError($redirectTo, 'INVALID_STATE', __('auth.oauth_invalid_state'));
        }

        try {
            $socialUser = Socialite::driver($this->resolveProvider($provider))
                ->stateless()
                ->user();
        } catch (\Throwable $e) {
            return $this->redirectWithError($redirectTo, 'OAUTH_FAILED', __('auth.oauth_failed'));
        }

        $email = $socialUser->getEmail();

        // بعض المزودين لا يشاركون البريد — لا يمكن إنشاء حساب دونه
        if (! $email) {
            return $this->redirectWithError($redirectTo, 'PROVIDER_EMAIL_REQUIRED', __('auth.provider_email_required'));
        }

        $user = User::where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        if (! $user) {
            // ربط حساب قائم بنفس البريد أو إنشاء جديد — الدور يُختار عند أول دخول (role = null)
            $user = User::where('email', $email)->first();

            if ($user && $user->provider === null) {
                $user->forceFill([
                    'provider' => $provider,
                    'provider_id' => $socialUser->getId(),
                ])->save();
            } elseif (! $user) {
                $user = User::create([
                    'name' => $socialUser->getName() ?? $email,
                    'email' => $email,
                    'password' => null,
                    'role' => null,               // ROLE_REQUIRED → الواجهة تفتح شاشة اختيار الدور
                    'provider' => $provider,
                    'provider_id' => $socialUser->getId(),
                    'email_verified_at' => now(), // بريد OAuth موثوق من المزود
                ]);
            }
        }

        $user->forceFill(['last_login_at' => now(), 'last_active_at' => now()])->save();

        $token = $user->createToken('api', ['*'], now()->addHours(User::TOKEN_EXPIRY_HOURS));

        $roleRequired = $user->role === null; // أول دخول OAuth — يختار الدور (SRS-F01-07)
        $roleSetupState = $roleRequired
            ? hash_hmac('sha256', $user->id.'|'.$provider, (string) config('app.key'))
            : null;

        // Redirect browser to the frontend SPA with the auth token in the fragment
        // so it never hits the server (token stays out of access logs).
        $params = http_build_query([
            'token' => $token->plainTextToken,
            'role' => $user->role?->value,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null ? '1' : '0',
            'role_required' => $roleRequired ? '1' : '0',
            'role_setup_state' => $roleSetupState ?? '',
            'provider' => $provider,
        ]);

        $separator = str_contains($redirectTo, '?') ? '&' : '?';
        return redirect()->away($redirectTo.$separator.$params);
    }

    /** Build a redirect to the frontend with error params. */
    private function redirectWithError(string $redirectTo, string $code, string $message): \Illuminate\Http\RedirectResponse
    {
        $separator = str_contains($redirectTo, '?') ? '&' : '?';
        return redirect()->away($redirectTo.$separator.http_build_query([
            'error' => $code,
            'error_message' => $message,
        ]));
    }

    /**
     * تثبيت الدور بعد أول دخول OAuth — POST /auth/{provider}/role (SRS-F01-07).
     * route داخل auth:sanctum + role.pending (role = null فقط).
     * يتحقق من state الموقّع (HMAC) قبل التعيين — يمنع تعيين الدور دون إكمال تدفق OAuth.
     */
    public function finalizeRole(string $provider, Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'role' => ['required', Rule::enum(UserRole::class)],
            'state' => ['required', 'string'],
        ]);

        $role = UserRole::from($data['role']);

        // admin لا يُثبَّت عبر هذا المسار — مثل التسجيل العام (SRS §1.2)
        if ($role->isAdmin()) {
            return $this->error('FORBIDDEN', __('auth.admin_registration_forbidden'), 403);
        }

        // التحقق من state الموقّع — يجب أن يطابق ما رجع في استجابة callback
        $expected = hash_hmac('sha256', $user->id.'|'.$provider, (string) config('app.key'));

        if (! hash_equals($expected, $data['state'])) {
            return $this->error('INVALID_STATE', __('auth.oauth_invalid_state'), 401);
        }

        $user->setRole($role);

        return $this->success([
            'user' => $user->fresh()->toApiArray(),
        ], __('auth.role_set'));
    }

    // ——————————————————————— إعادة تعيين كلمة المرور (SRS-F01-04 · ساعة واحدة) ———————————————————————

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
        ]);

        // لا نكشف وجود البريد — استجابة موحّدة دائماً
        $status = Password::broker()->sendResetLink(['email' => $data['email']]);

        $sent = in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER], true);

        return $this->success(['reset_link_sent' => $sent], __('auth.reset_link_sent'));
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::broker()->reset(
            $data,
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return $this->error('INVALID_RESET_TOKEN', __('auth.invalid_reset_token'), 422);
        }

        return $this->success(['reset' => true], __('auth.password_reset_done'));
    }
}
