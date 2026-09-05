<?php

namespace App\Providers;

use App\Models\Agreement;
use App\Models\Evaluation;
use App\Models\Interest;
use App\Models\Project;
use App\Policies\AgreementPolicy;
use App\Policies\EvaluationPolicy;
use App\Policies\InterestPolicy;
use App\Policies\ProjectPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

/**
 * T162 — تسجيل سياسات التفويض.
 *
 * Laravel 13 يكتشف الـ policies تلقائياً بالاصطلاح (Model + Policy) —
 * التسجيل هنا صريح لتوثيق الاقتران وتوحيد نقطة التسجيل مستقبلاً.
 */
class AuthServiceProvider extends ServiceProvider
{
    /**
     * خرائط النماذج → سياساتها.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Project::class => ProjectPolicy::class,
        // US-029: سياسة عرض تقرير AI الكامل (L3/EX/AD) — يمر بها ReportController والتصدير.
        Evaluation::class => EvaluationPolicy::class,
        // EPIC-08: طلبات الاهتمام + مستند الاتفاق (US-044/045).
        Interest::class => InterestPolicy::class,
        Agreement::class => AgreementPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
