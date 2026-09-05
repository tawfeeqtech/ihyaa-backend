<?php

namespace App\Policies;

use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use App\Services\Agreements\AgreementRepository;

/**
 * سياسة عرض تقرير AI كاملاً — مصفوفة الإفصاح (contracts/report-api.md §3 · US-029).
 *
 * viewFullReport: L3/EX/AD فقط (المالك، المستثمر بعد اتفاق نشط، المشرف) —
 * نفس النقطة التي يستخدمها ReportController للتصدير والقراءة الكاملة.
 *
 * يُسجَّل في AuthServiceProvider (Policy كاملة) ويستدعي AgreementRepository
 * مباشرة (لا يمر عبر DisclosureService حتى لا يكون اعتماداً دائرياً — الخدمة تستدعي الـ Policy).
 */
class EvaluationPolicy
{
    public function __construct(
        private readonly AgreementRepository $agreements,
    ) {
    }

    /** هل يرى المحتوى الكامل (فجوات + توصيات + مهارات + SWOT + تصدير PDF)؟ */
    public function viewFullReport(User $user, Evaluation $evaluation): bool
    {
        $project = $evaluation->relationLoaded('project')
            ? $evaluation->project
            : $evaluation->project()->first();

        if ($project === null) {
            return false;
        }

        if ($user->isAdmin() || $project->isOwner($user)) {
            return true;
        }

        return $this->agreements->hasActiveAgreement($project->id, $user->id);
    }
}
