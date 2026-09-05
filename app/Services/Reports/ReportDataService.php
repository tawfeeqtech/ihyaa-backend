<?php

namespace App\Services\Reports;

use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use App\Services\Disclosure\DisclosureService;

/**
 * خدمة بيانات تقرير AI — contracts/report-api.md §1 (T091).
 *
 * لا يقرأ المتحكم JSON مباشرة — كل قراءة تمر عبر DisclosureService::shapeFor()
 * (نقطة الفرض الوحيدة). هنا نتحقق أيضاً من قابلية التقرير (التبليغ 404 للتقييمات
 * الفاشلة بلا تقرير — report-api.md §5).
 */
class ReportDataService
{
    public function __construct(
        private readonly DisclosureService $disclosure,
    ) {
    }

    /**
     * @return array<string, mixed> شكل بيانات التقرير حسب مستوى الإفصاح
     */
    public function get(Evaluation $evaluation, Project $project, ?User $user): array
    {
        return $this->disclosure->shapeFor($evaluation, $project, $user);
    }
}
