<?php

namespace App\Providers;

use App\Models\Evaluation;
use App\Models\Project;
use App\Observers\EvaluationObserver;
use App\Observers\ProjectObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        // مزامنة فهرس البحث تلقائياً عند إنشاء/تعديل/حذف/استرجاع المشاريع (plan §5.3 · T127)
        Project::observe(ProjectObserver::class);

        // T050: مصدر الأحداث النهائية للتقييم (completed/failed/partial) — يطلقها المرصاد
        // عند `saved` بحالة نهائية بدل تصريح مباشر من الخدمة (لا إطلاق مزدوج).
        Evaluation::observe(EvaluationObserver::class);

        // رابط إعادة تعيين كلمة المرور يشير إلى الواجهة (Next.js) وليس إلى مسار Laravel (SPA/API — US-004)
        ResetPassword::createUrlUsing(function ($user, string $token) {
            return config('app.frontend_url')
                .'/'.config('app.locale')
                .'/reset-password?token='.$token
                .'&email='.urlencode($user->getEmailForPasswordReset());
        });
    }

    /**
     * Named Rate Limiters — المستويات السبعة (rate-limiting-spec §6 · docs/api/routes.md §3).
     * العدادات في Redis/ذاكرة التخزين فقط — لا جدول rate_limits في MySQL.
     */
    protected function configureRateLimiting(): void
    {
        // ---------------- L1: المصادقة (SRS-NFR-17 · plan.md Rate Limiter Definitions) ----------------
        RateLimiter::for('api.register', fn (Request $r) => Limit::perMinute(3)->by($r->ip()));                                  // SRS-API-01 · RL-AUTH-01 · 3/دقيقة
        RateLimiter::for('api.login', fn (Request $r) => Limit::perMinute(5)->by($r->input('email') ?: $r->ip()));              // SRS-API-02 · RL-AUTH-02 · 5/دقيقة · email
        RateLimiter::for('api.logout', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->id ?: $r->ip()));                // SRS-API-03 · RL-AUTH-03 · 10/دقيقة · user_id
        RateLimiter::for('api.otp', fn (Request $r) => Limit::perMinute(3)->by($r->input('email') ?: $r->ip()));                 // SRS-API-04 · RL-AUTH-04 · 3/دقيقة · email (منع توزيع الهجوم)
        RateLimiter::for('api.forgot', fn (Request $r) => Limit::perMinute(2)->by($r->input('email') ?: $r->ip()));              // SRS-API-05 · RL-AUTH-05 · 2/دقيقة · email
        RateLimiter::for('api.reset', fn (Request $r) => Limit::perMinute(2)->by($r->input('email') ?: $r->ip()));               // SRS-API-06 · RL-AUTH-06 · 2/دقيقة · email
        RateLimiter::for('api.oauth', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));                                     // SRS-API-07/08 · RL-AUTH-07/08 · 5/دقيقة

        // ---------------- L2: التصفح العام (IP) ----------------
        RateLimiter::for('public.browse', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));                                   // RL-PUB-01/03/04/05
        RateLimiter::for('public.detail', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));                                   // RL-PUB-02 (مخزّن مؤقتاً)

        // ---------------- L3: صاحب فكرة ----------------
        RateLimiter::for('idea-owner.read', fn (Request $r) => Limit::perMinute(60)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('idea-owner.write', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->id ?: $r->ip()));

        // ---------------- L4: مستثمر ----------------
        RateLimiter::for('investor.read', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('investor.write', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->id ?: $r->ip()));
        // saved-projects-api.md §0 — حفظ/إزالة المحفوظات: 30/دقيقة (POST/DELETE)
        RateLimiter::for('investor.saved', fn (Request $r) => Limit::perMinute(30)->by($r->user()?->id ?: $r->ip())); // RL-INV-07/08

        // ---------------- L5: العمليات المكلفة (AI + رفع) ----------------
        // RL-AI-01 · user+project — throttle يسبق ربط النموذج، لذا route('project') قد يكون
        // معرّفاً (string) وليس نموذج Project؛ نتعامل مع الحالتين (T103/T121).
        RateLimiter::for('ai.analyze', function (Request $r) {
            $project = $r->route('project');
            $projectId = $project instanceof Project ? $project->id : $project;

            return Limit::perMinute(3)->by(($r->user()?->id ?: $r->ip()).':'.$projectId);
        });
        RateLimiter::for('ai.evaluate', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->id ?: $r->ip()));                // SRS-API-44..46 · 10/دقيقة/مستخدم (evaluation-api.md §1)
        RateLimiter::for('ai.report', fn (Request $r) => Limit::perHour(20)->by($r->user()?->id ?: $r->ip()));                    // RL-AI-02 + SRS-API-48 · 20/ساعة/مستخدم (sprint2)
        RateLimiter::for('ai.search', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));                                     // search-api.md · 60/دقيقة/عنوان IP
        RateLimiter::for('upload.file', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->id ?: $r->ip()));                // RL-IO-03/07

        // ---------------- L6: المشرف (tasks.md T008 · T086) ----------------
        RateLimiter::for('admin.analytics', fn (Request $r) => Limit::perMinute(30)->by($r->user()?->id ?: $r->ip()));           // RL-ADM-01 · 30/دقيقة (EPIC-12)
        RateLimiter::for('admin.export', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->id ?: $r->ip()));                // RL-ADM-02 · 10/دقيقة
        RateLimiter::for('admin.read', fn (Request $r) => Limit::perMinute(60)->by($r->user()?->id ?: $r->ip()));                // RL-ADM-01 · 60/دقيقة (احتياطي/قديم)

        // ---------------- L3+L4: نقاط مشتركة (حد حسب الدور) ----------------
        RateLimiter::for('shared.read', function (Request $r) {
            $user = $r->user();
            $limit = $user && $user->role === 'investor' ? 120 : 60;

            return Limit::perMinute($limit)->by($user?->id ?: $r->ip());
        });
        RateLimiter::for('shared.write', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->id ?: $r->ip()));
        // dashboard-api.md §0 · trash-api.md §0 — 60/دقيقة للوحات، 30/10 للمهملات.
        RateLimiter::for('dashboard', fn (Request $r) => Limit::perMinute(60)->by($r->user()?->id ?: $r->ip()));                // RL-IO-09/RL-INV-09 · dashboard-api.md §0
        RateLimiter::for('trash.read', fn (Request $r) => Limit::perMinute(30)->by($r->user()?->id ?: $r->ip()));                // RL-IO-10 · trash-api.md §0 · 30/دقيقة
        RateLimiter::for('trash.write', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->id ?: $r->ip()));               // RL-IO-11/12 · trash-api.md §0 · 10/دقيقة

        // ---------------- EPIC-09: الإشعارات (RL-SH-05..08 · tasks.md T008/T067) ----------------
        RateLimiter::for('notifications.read', fn (Request $r) => Limit::perMinute(60)->by($r->user()?->id ?: $r->ip()));     // RL-SH-05/06 · 60/دقيقة
        RateLimiter::for('notifications.unread', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));  // RL-SH-08 · 120/دقيقة (جرس — مرتفع)
        RateLimiter::for('notifications.write', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->id ?: $r->ip()));    // RL-SH-07 · 10/دقيقة
    }
}
