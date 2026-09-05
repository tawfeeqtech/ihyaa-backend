<?php

use App\Exceptions\Handler as ApiExceptionHandler;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\EnforceReportDisclosure;
use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Middleware\IdeaOwnerMiddleware;
use App\Http\Middleware\InvestorMiddleware;
use App\Http\Middleware\PendingRoleMiddleware;
use App\Http\Middleware\RefreshSanctumToken;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackRateLimitViolations;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        channels: __DIR__.'/../routes/channels.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // يعمل قبل باقي Middleware في مجموعة api — لترجمة رسائل 429 (Arabic-first)
        $middleware->api(prepend: [SetLocale::class]);

        $middleware->alias([
            'idea-owner' => IdeaOwnerMiddleware::class,
            'investor' => InvestorMiddleware::class,
            'admin' => AdminMiddleware::class,
            'email.verified' => EnsureEmailVerified::class,
            'token.refresh' => RefreshSanctumToken::class,
            'rate.violations' => TrackRateLimitViolations::class,
            'role.pending' => PendingRoleMiddleware::class,
            // EPIC-05: مصفوفة الإفصاح عن تقرير AI — يفرض المستوى ويرفض الحقول المحمية (US-029).
            'report.disclosure' => EnforceReportDisclosure::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // T028 — كل رسم الاستثناءات → غلاف خطأ JSON الموحّد (app/Exceptions/Handler.php).
        ApiExceptionHandler::register($exceptions);
    })->create();
