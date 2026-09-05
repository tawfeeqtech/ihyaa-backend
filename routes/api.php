<?php

use App\Http\Controllers\Api\AdminAnalyticsController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AgreementController;
use App\Http\Controllers\Api\AIAgentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EvaluationController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\InterestController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SavedProjectController;
use App\Http\Controllers\Api\SavedProjectsController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TrashController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| L7 — Health & Meta (بدون Rate Limit — IP داخلي فقط في الإنتاج)
|--------------------------------------------------------------------------
*/
Route::get('/health', [HealthController::class, 'index']);
Route::get('/ready', [HealthController::class, 'ready']);

/*
|--------------------------------------------------------------------------
| L1 — المصادقة (Public — حدود صارمة)
| SRS-API-01..08
|--------------------------------------------------------------------------
*/
Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:api.register');                           // SRS-API-01 · RL-AUTH-01 · 3/دقيقة · IP

Route::post('/login', [AuthController::class, 'login'])
    ->middleware(['rate.violations', 'throttle:api.login']);         // SRS-API-02 · RL-AUTH-02 · 5/دقيقة · email

Route::post('/email/resend', [AuthController::class, 'resendOtp'])
    ->middleware('throttle:api.otp');                                // إعادة إرسال رمز التفعيل · عام · 3/دقيقة

Route::post('/email/verify', [AuthController::class, 'verifyEmail'])
    ->middleware('throttle:api.otp');                                // SRS-API-04 · RL-AUTH-04 · عام · 3/دقيقة
// Body: {email, code?} — code غائب = إعادة إرسال رمز جديد (UC-01 A2)
// عام بلا توكن لأن register لا يُصدر توكن قبل التفعيل (الدستور V · T124/T139)

Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:api.forgot');                             // SRS-API-05 · RL-AUTH-05 · 2/دقيقة · email

Route::post('/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:api.reset');                              // SRS-API-06 · RL-AUTH-06 · 2/دقيقة · email

Route::get('/auth/{provider}', [AuthController::class, 'redirectToProvider'])
    ->whereIn('provider', ['google', 'github', 'linkedin'])
    ->middleware('throttle:api.oauth');                              // SRS-API-07 · RL-AUTH-07 · 5/دقيقة · IP

// GET: المزوّد يُعيد التوجيه مباشرة بعد المصادقة (OAuth2 standard redirect)
Route::get('/auth/{provider}/callback', [AuthController::class, 'handleProviderCallback'])
    ->whereIn('provider', ['google', 'github', 'linkedin'])
    ->middleware('throttle:api.oauth');

// POST: الواجهة الأمامية (SPA) ترسل الكود للمعالجة
Route::post('/auth/{provider}/callback', [AuthController::class, 'handleProviderCallback'])
    ->whereIn('provider', ['google', 'github', 'linkedin'])
    ->middleware('throttle:api.oauth');                              // SRS-API-08 · RL-AUTH-08 · 5/دقيقة · IP

/*
|--------------------------------------------------------------------------
| L2 — التصفح العام (Public — IP)
| SRS-API-13, 14, 20, 21, 12, 49
|--------------------------------------------------------------------------
*/
Route::get('/projects', [ProjectController::class, 'index'])
    ->middleware('throttle:public.browse');                          // RL-PUB-01 · 30/دقيقة

Route::get('/projects/{project}', [ProjectController::class, 'show'])
    ->middleware('throttle:public.detail');                          // RL-PUB-02 · 60/دقيقة (مخزّن مؤقتاً)

Route::get('/search', [SearchController::class, 'search'])
    ->middleware('throttle:ai.search');                              // search-api.md · 60/دقيقة/عنوان IP

Route::get('/search/suggestions', [SearchController::class, 'suggestions'])
    ->middleware('throttle:ai.search');                              // search-api.md · 60/دقيقة/عنوان IP

Route::get('/tags/suggestions', [TagController::class, 'suggestions'])
    ->middleware('throttle:public.browse');                          // SRS-API-49 · L2 · 30/دقيقة

Route::get('/categories', [CategoryController::class, 'index'])
    ->middleware('throttle:public.browse');                          // SRS-F02-01 · L2 · 30/دقيقة

Route::get('/profile/{user}', [ProfileController::class, 'showPublic'])
    ->middleware('throttle:public.browse');                          // RL-PUB-05 · 30/دقيقة

/*
|--------------------------------------------------------------------------
| المصادق عليهم — Sanctum (جميع المسارات التالية)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'token.refresh'])->group(function () {

    /*
    | L1 — استكمال المصادقة (مصادق)
    |------------------------------------------------------------------------
    */
    Route::post('/logout', [AuthController::class, 'logout'])
        ->middleware('throttle:api.logout');                         // SRS-API-03 · RL-AUTH-03 · 10/دقيقة · user_id

    // تثبيت الدور بعد أول دخول OAuth — مصادق + role.pending (role = null فقط)
    Route::post('/auth/{provider}/role', [AuthController::class, 'finalizeRole'])
        ->whereIn('provider', ['google', 'github', 'linkedin'])
        ->middleware(['role.pending', 'throttle:api.oauth']);        // SRS-F01-07 · RL-AUTH-07 · 5/دقيقة

    Route::get('/me', [AuthController::class, 'me'])
        ->middleware('throttle:shared.read');

    /*
    | L3/L4 — الملف الشخصي (Shared — read حسب الدور)
    | SRS-API-09..11
    |------------------------------------------------------------------------
    */
    Route::get('/profile', [ProfileController::class, 'show'])
        ->middleware('throttle:shared.read');                        // RL-IO-01 / RL-INV-01 · 60/120/دقيقة

    Route::put('/profile', [ProfileController::class, 'update'])
        ->middleware('throttle:shared.write');                       // RL-IO-02 / RL-INV-02 · 10/دقيقة
    // يُسمح بحقل role فقط عندما role=null (أول دخول OAuth — SRS-F01-07)

    Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar'])
        ->middleware('throttle:upload.file');                        // RL-IO-03 / RL-INV-03 · L5 · 10/دقيقة

    /*
    | L3-W — إدارة المشاريع (Idea Owner — Policies داخلية للتحقق من الملكية)
    | SRS-API-15..17
    |------------------------------------------------------------------------
    */
    Route::middleware(['idea-owner', 'email.verified'])->group(function () {

        Route::post('/projects', [ProjectController::class, 'store'])
            ->middleware('throttle:idea-owner.write');               // RL-IO-04 · 10/دقيقة

        Route::put('/projects/{project}', [ProjectController::class, 'update'])
            ->middleware('throttle:idea-owner.write');               // RL-IO-05 · 10/دقيقة

        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])
            ->middleware('throttle:idea-owner.write');               // RL-IO-06 · 10/دقيقة

        Route::delete('/projects/{project}/files/{file}', [FileController::class, 'destroy'])
            ->middleware('throttle:idea-owner.write');               // امتداد مقترح (تجربة رفع كاملة)

        /*
        | L5 — رفع الملفات (Idea Owner — مالك المشروع)
        | SRS-API-18
        |----------------------------------------------------------------------
        */
        Route::post('/projects/{project}/files', [FileController::class, 'upload'])
            ->middleware('throttle:upload.file');                    // RL-IO-07 · L5 · 10/دقيقة

        /*
        | L5 — التقييم وإعادة التقييم (Idea Owner — مالك المشروع)
        | SRS-API-44..47 · 3/دقيقة لكل (user_id + project_id) + Cache 24 ساعة
        |----------------------------------------------------------------------
        */
        Route::post('/projects/{project}/evaluate', [EvaluationController::class, 'evaluate'])
            ->middleware('throttle:ai.evaluate');

        Route::post('/projects/{project}/re-evaluate', [EvaluationController::class, 'reEvaluate'])
            ->middleware('throttle:ai.evaluate');

        Route::post('/projects/{project}/evaluations/{evaluation}/retry',
            [EvaluationController::class, 'retry'])
            ->middleware('throttle:ai.evaluate');

        Route::get('/projects/{project}/evaluation-status',
            [EvaluationController::class, 'status'])
            ->middleware('throttle:shared.read');

        /*
        | L3 — سلة المهملات (Idea Owner)
        | SRS-API-35..37
        |----------------------------------------------------------------------
        */
        Route::get('/trashed-projects', [TrashController::class, 'index'])
            ->middleware('throttle:trash.read');                     // RL-IO-10 · trash-api.md §0 · 30/دقيقة

        // withTrashed: ربط المشاريع المحذوفة ناعماً (سلة المهملات)
        Route::post('/trashed-projects/{project}/restore', [TrashController::class, 'restore'])
            ->withTrashed()
            ->middleware('throttle:trash.write');                    // RL-IO-11 · trash-api.md §0 · 10/دقيقة

        Route::delete('/trashed-projects/{project}/force', [TrashController::class, 'forceDelete'])
            ->withTrashed()
            ->middleware('throttle:trash.write');                    // RL-IO-12 · trash-api.md §0 · 10/دقيقة

        /*
        | L5 — وكيل AI: تحليل المشاريع (Idea Owner — مالك المشروع)
        | SRS-API-42..43
        |----------------------------------------------------------------------
        */
        Route::post('/ai/analyze/{project}', [AIAgentController::class, 'analyze'])
            ->middleware('throttle:ai.analyze');                     // RL-AI-01 · 3/دقيقة · user+project

        Route::get('/ai/analysis/{artifact}', [AIAgentController::class, 'show'])
            ->middleware('throttle:ai.report');                      // RL-AI-02 · 10/دقيقة

        Route::get('/ai/analysis/{artifact}/export', [AIAgentController::class, 'export'])
            ->middleware('throttle:ai.report');                      // T118 · RL-AI-02 · PDF (المالك فقط)

        Route::get('/projects/{project}/ai-analysis', [AIAgentController::class, 'projectAnalysis'])
            ->middleware('throttle:shared.read');                    // T115 · أحدث إصدار لكل نوع (المالك فقط)
    });

    /*
    | L4-W — الاهتمام والمحفوظات (Investor)
    | SRS-API-22, 33, 34
    |------------------------------------------------------------------------
    */
    Route::middleware(['investor', 'email.verified'])->group(function () {

        // withTrashed: المشروع المحذوف (سلة) يصل إلى الخدمة → 422 project_unavailable
        // بدل 404 (UC-06 E2 · contract §1).
        Route::post('/projects/{project}/interest', [InterestController::class, 'store'])
            ->withTrashed()
            ->middleware('throttle:investor.write');                 // RL-INV-04 · 10/دقيقة

        // withoutTrashed في DELETE: المشروع المحذوف soft يبقى قابلاً للإزالة من
        // المحفوظات (saved-projects-api.md §3 — available:false + زر إزالة يعمل).
        // أما POST بلا withTrashed → مشروع soft-deleted يُرجع 404 (contract §2).
        Route::post('/projects/{project}/save', [SavedProjectsController::class, 'store'])
            ->middleware('throttle:investor.saved');                 // RL-INV-07 · 30/دقيقة (saved-projects-api §0)

        Route::delete('/projects/{project}/save', [SavedProjectsController::class, 'destroy'])
            ->withTrashed()
            ->middleware('throttle:investor.saved');                 // RL-INV-08 · 30/دقيقة

        Route::get('/saved-projects', [SavedProjectsController::class, 'index'])
            ->middleware('throttle:shared.read');                    // RL-INV-06 · 60/دقيقة
    });

    /*
    | L3/L4 — نقاط مشتركة (Shared — role policies داخلية)
    | SRS-API-19, 23..27, 28..31
    |------------------------------------------------------------------------
    */
    Route::get('/projects/{project}/evaluations', [EvaluationController::class, 'history'])
        ->middleware('throttle:shared.read');                        // SRS-API-19 · آخر 5 مكتملة (الإفصاح حسب الدور)

    /*
    | L3 — تقرير AI (Shared — الإفصاح حسب مصفوفة US-029 على الخادم)
    | contracts/report-api.md §1/§2 · SRS-API-19/48 · EnforceReportDisclosure
    |------------------------------------------------------------------------
    | GET .../evaluations/{evaluation}        → 200 بيانات التقرير (المحتوى حسب المستوى)
    | GET .../evaluations/{evaluation}/report → 200 PDF (L3/EX/AD فقط) · 403 PDF_EXPORT_DENIED
    */
    Route::get('/projects/{project}/evaluations/{evaluation}',
        [ReportController::class, 'show'])
        ->middleware(['report.disclosure', 'throttle:shared.read']); // SRS-API-19 · مصفوفة الإفصاح

    Route::get('/projects/{project}/evaluations/{evaluation}/report',
        [ReportController::class, 'export'])
        ->middleware(['report.disclosure', 'throttle:ai.report']);   // SRS-API-48 · RL-AI-02 · 10/دقيقة

    Route::get('/interests/received', [InterestController::class, 'received'])
        ->middleware('throttle:shared.read');                        // RL-SH-01 · L3 · 30/دقيقة (IO)

    Route::get('/interests/sent', [InterestController::class, 'sent'])
        ->middleware('throttle:shared.read');                        // RL-INV-05 · 60/دقيقة (Investor)

    Route::put('/interests/{interest}/accept', [InterestController::class, 'accept'])
        ->middleware('throttle:shared.write');                       // RL-SH-02 · 10/دقيقة (IO — مالك المشروع)

    Route::put('/interests/{interest}/reject', [InterestController::class, 'reject'])
        ->middleware('throttle:shared.write');                       // RL-SH-03 · 10/دقيقة (IO — مالك المشروع)

    // T089: PUT بدل POST — dashboard-api.md §3 (UC-07 E2 · Investor)
    Route::put('/interests/{interest}/cancel', [InterestController::class, 'cancel'])
        ->middleware('throttle:shared.write');                       // UC-07 E2 (Investor)

    Route::get('/agreements/{agreement}', [AgreementController::class, 'show'])
        ->middleware('throttle:shared.read');                        // RL-SH-04 · 10/دقيقة (الطرفان)

    Route::get('/notifications', [NotificationController::class, 'index'])
        ->middleware('throttle:notifications.read');                // RL-SH-05 · T008 · 60/دقيقة (EPIC-09)

    Route::get('/notifications/recent', [NotificationController::class, 'recent'])
        ->middleware('throttle:notifications.read');                // RL-SH-05 · T067 · 60/دقيقة — آخر 5 للجرس

    Route::put('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->middleware('throttle:notifications.read');                // RL-SH-06 · T067 · 60/دقيقة

    Route::put('/notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->middleware('throttle:notifications.write');               // RL-SH-07 · T067 · 10/دقيقة

    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])
        ->middleware('throttle:notifications.unread');              // RL-SH-08 · T008 · 120/دقيقة

    /*
    | L3/L4 — لوحات التحكم (Dashboard — 20/دقيقة)
    | SRS-API-38..39
    |------------------------------------------------------------------------
    */
    Route::get('/dashboard/idea-owner', [DashboardController::class, 'ideaOwner'])
        ->middleware(['idea-owner', 'throttle:dashboard']);          // RL-IO-09 · 20/دقيقة

    Route::get('/dashboard/investor', [DashboardController::class, 'investor'])
        ->middleware(['investor', 'throttle:dashboard']);            // RL-INV-09 · 20/دقيقة

    /*
    | L6 — المشرف (Admin — seeder فقط)
    | SRS-API-40..41
    |------------------------------------------------------------------------
    */
    Route::middleware('admin')->group(function () {

        Route::get('/admin/analytics', [AdminAnalyticsController::class, 'analytics'])
            ->middleware('throttle:admin.analytics');                // RL-ADM-01 · 30/دقيقة (tasks.md T086)

        Route::get('/admin/analytics/export', [AdminAnalyticsController::class, 'export'])
            ->middleware('throttle:admin.export');                   // RL-ADM-02 · 10/دقيقة
    });
});
