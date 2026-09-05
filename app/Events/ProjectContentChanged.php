<?php

namespace App\Events;

use App\Models\Project;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * حدث تغيّر محتوى مشروع (US-022 / FR-226/228) — الحقول الجوهرية:
 * description · tags · github_url · status (أي تغيير = substantial_changes: true).
 *
 * يُطلق بعد `PUT /api/projects/{project}` عندما يكتشف الخادم تغييراً جوهرياً.
 * المستمع `InvalidateEvaluationCache` لا يُبطل الكاش هنا (SRS-AI-C02: "ليس الآن" ← لا إبطال) —
 * القرار دائماً بيد المستخدم، ويُحذف الكاش فقط عند تأكيد إعادة التقييم في EvaluationService.
 *
 * ملاحظة النطاق: نقطة الإطلاق (ProjectController::update / ProjectObserver::saved)
 * خارج حدود ملكية ملفات Sprint 2 glue — الحدث جاهز ويُربط عند تعديل تلك النقاط.
 */
class ProjectContentChanged
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $substantialFields  الحقول الجوهرية التي تغيّرت
     */
    public function __construct(
        public Project $project,
        public array $substantialFields = [],
    ) {
    }
}
