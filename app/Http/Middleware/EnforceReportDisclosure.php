<?php

namespace App\Http\Middleware;

use App\Models\Evaluation;
use App\Models\Project;
use App\Services\Disclosure\DisclosureService;
use App\Services\Disclosure\DisclosureLevel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * نقطة فرض مصفوفة الإفصاح على مستوى الطلب — contracts/report-api.md §3 (US-029).
 *
 * - يحسب المستوى الفعلي (L1/L2/L3/EX/AD) عبر DisclosureService (PEP وحيد)
 *   ويحقنه في الطلب كـ `disclosure_context` (يقرأه المتحكم ولا يعيد الحساب).
 * - يرفض 403 أي `?include=` يتجاوز المستوى (لا إهمال صامت — SRS §1.4).
 *
 * المسار: GET /api/projects/{project}/evaluations/{evaluation}[/report]
 * معامِلات المسار أسماء نماذج Laravel (ربط تلقائي).
 */
class EnforceReportDisclosure
{
    public const CONTEXT_ATTRIBUTE = 'disclosure_context';

    public function __construct(
        private readonly DisclosureService $disclosure,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');
        $evaluation = $request->route('evaluation');

        if (! $project instanceof Project || ! $evaluation instanceof Evaluation) {
            return $next($request);
        }

        // التقييم يجب أن يخص المشروع (تقييم "منطق" عبر مسار خاطئ = 404 — لا 403).
        if ((int) $evaluation->project_id !== (int) $project->id) {
            return $request->expectsJson()
                ? response()->json([
                    'success' => false,
                    'code' => 'NOT_FOUND',
                    'message' => __('errors.not_found'),
                    'errors' => null,
                ], 404)
                : abort(404);
        }

        $user = $request->user();
        $context = $this->disclosure->contextFor($user, $project);

        $request->attributes->set(self::CONTEXT_ATTRIBUTE, $context);

        // رفض الحقول المحمية المطلوبة صراحة (لا إهمال صامت — SRS §1.4).
        $protected = ['gap_analysis', 'recommendations', 'required_skills', 'swot', 'warnings', 'partial_dimensions'];
        $requested = array_filter(explode(',', (string) $request->query('include', '')));

        if ($requested !== [] && ! $context->canViewFull) {
            $intersection = array_intersect($protected, $requested);

            if ($intersection !== []) {
                return $request->expectsJson()
                    ? response()->json([
                        'success' => false,
                        'code' => 'DISCLOSURE_LEVEL_INSUFFICIENT',
                        'message' => 'محتوى التقرير الكامل متاح بعد الاتفاق مع صاحب المشروع',
                        'errors' => null,
                    ], 403)
                    : abort(403, 'محتوى التقرير الكامل متاح بعد الاتفاق مع صاحب المشروع');
            }
        }

        return $next($request);
    }
}
