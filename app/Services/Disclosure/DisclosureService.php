<?php

namespace App\Services\Disclosure;

use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use App\Services\Agreements\AgreementRepository;

/**
 * مصفوفة الإفصاح عن تقرير AI — contracts/report-api.md §3 (US-029 / FR-236/237).
 *
 * نقطة الفرض الوحيدة (Policy Enforcement Point) لشكل استجابة التقرير: كل نقاط
 * التقرير (ReportDataService / ReportController / تصدير PDF) تمر عبر
 * resolveLevel()/shapeFor() — إخفاء الواجهة وحده غير كافٍ، وأي طلب لحقل محمي
 * يُرفض 403 في المتحكم قبل التشكيل.
 */
class DisclosureService
{
    /** الأسماء القياسية للأبعاد الخمسة بترتيب config('ai.weights'). */
    private const DIMENSION_LABELS_AR = [
        'technical_quality' => 'الجودة التقنية',
        'innovation' => 'الابتكار',
        'market_viability' => 'الجدوى السوقية',
        'team_completeness' => 'اكتمال الفريق',
        'documentation' => 'التوثيق',
    ];

    private const TEAM_WARNING = 'معلومات الفريق غير كافية — قد يؤثر على دقة بُعد اكتمال الفريق';

    public function __construct(
        private readonly AgreementRepository $agreements,
    ) {
    }

    // ——————————————————————— المستوى ———————————————————————

    /**
     * المستوى الفعلي حسب مصفوفة الإفصاح:
     * AD مشرف · EX صاحب المشروع · L3 بعد اتفاق · L2 مسجل · L1 زائر.
     */
    public function resolveLevel(?User $user, Project $project): DisclosureLevel
    {
        if ($user?->isAdmin()) {
            return DisclosureLevel::AD;
        }

        if ($project->isOwner($user)) {
            return DisclosureLevel::EX;
        }

        if ($user !== null && $this->agreements->hasActiveAgreement($project->id, $user->id)) {
            return DisclosureLevel::L3;
        }

        if ($user !== null) {
            return DisclosureLevel::L2;
        }

        return DisclosureLevel::L1;
    }

    /** هل يرى المحتوى الكامل (فجوات + توصيات + مهارات + SWOT + تصدير)؟ */
    public function canViewFull(?User $user, Project $project): bool
    {
        return $this->resolveLevel($user, $project)->canViewFull();
    }

    /** بناء سياق الإفصاح لحقنه في الطلب (EnforceReportDisclosure). */
    public function contextFor(?User $user, Project $project): DisclosureContext
    {
        return DisclosureContext::from(
            $this->resolveLevel($user, $project),
            $project->isOwner($user),
        );
    }

    // ——————————————————————— التشكيل (نقطة الفرض) ———————————————————————

    /**
     * تشكيل استجابة بيانات التقرير الكاملة حسب المستوى — contracts/report-api.md §1.
     *
     * @param  array<string, mixed>  $include  طلبات `?include=` (تُرفض في المتحكم إن تجاوزت المستوى)
     * @return array<string, mixed>
     */
    public function shapeFor(Evaluation $evaluation, Project $project, ?User $user, array $include = []): array
    {
        $level = $this->resolveLevel($user, $project);
        $full = $level->canViewFull();
        $result = is_array($evaluation->result) ? $evaluation->result : [];

        $report = [
            'id' => $evaluation->id,
            'version' => $evaluation->version,
            'status' => $evaluation->status->value,
            'overall_score' => $evaluation->overall_score,
            'confidence_score' => $evaluation->confidence_score,
            'model_used' => $evaluation->model_used?->value,
            'completed_at' => $evaluation->completed_at?->toISOString(),
        ];

        // L2+: درجات الأبعاد الخمسة + المعايير الفرعية (والنصية للكامل)
        if ($level->canViewDimensions()) {
            $report['dimensions'] = $this->dimensions($result, $full);
        }

        // الكامل فقط: فجوات + توصيات + مهارات + تحذيرات
        if ($full) {
            $report['gap_analysis'] = $this->gapCategories($result);
            $report['recommendations'] = $result['recommendations'] ?? [];
            $report['required_skills'] = $result['required_skills'] ?? [];
            $report['warnings'] = $result['warnings'] ?? [];
            $report['evaluation_timestamp'] = $evaluation->completed_at?->toISOString()
                ?? $evaluation->created_at?->toISOString();
            $report['partial_dimensions'] = $result['partial_dimensions'] ?? $this->partialDimensions($result);
        }

        $data = [
            'evaluation' => $report,
            'access_level' => $level->value,
            // مصدر واحد: الرادار يُبنى من الأبعاد المخزَّنة فقط (US-025-S2)
            'radar_chart' => ['axes' => $this->radarAxes($result)],
        ];

        if ($full) {
            $data['swot'] = $result['swot'] ?? [
                'strengths' => [],
                'weaknesses' => [],
                'opportunities' => [],
                'threats' => [],
            ];
            $data['team_meta'] = $this->teamMeta($result, $project);
            $data['export'] = [
                'pdf_url' => $this->pdfUrl($project, $evaluation),
                'allowed' => true,
            ];
        }

        return $data;
    }

    // ——————————————————————— أدوات ———————————————————————

    /**
     * محاور الرادار — من الأبعاد المخزَّنة فقط، بالترتيب القياسي،
     * وبالأبعاد المكتملة فقط في التقرير الجزئي (T090).
     *
     * @return list<array{dimension: string, label_ar: string, value: float}>
     */
    private function radarAxes(array $result): array
    {
        $dimensions = is_array($result['dimensions'] ?? null) ? $result['dimensions'] : [];
        $axes = [];

        foreach (array_keys(config('ai.weights')) as $key) {
            if (! array_key_exists($key, $dimensions)) {
                continue;
            }
            $entry = $dimensions[$key];
            $score = is_array($entry) ? ($entry['score'] ?? null) : $entry;

            if ($score === null || $score === '') {
                continue;
            }

            $axes[] = [
                'dimension' => $key,
                'label_ar' => self::DIMENSION_LABELS_AR[$key] ?? $key,
                'value' => round((float) $score, 1),
            ];
        }

        return $axes;
    }

    /**
     * درجات الأبعاد — مع sub_scores دائماً، والنصية (strengths/weaknesses) للكامل فقط.
     *
     * @return array<string, mixed>
     */
    private function dimensions(array $result, bool $full): array
    {
        $dimensions = is_array($result['dimensions'] ?? null) ? $result['dimensions'] : [];
        $out = [];

        foreach ($dimensions as $key => $entry) {
            if (! is_array($entry)) {
                $out[$key] = ['score' => $entry];
                continue;
            }

            $item = ['score' => $entry['score'] ?? null];

            if (array_key_exists('sub_scores', $entry)) {
                $item['sub_scores'] = $entry['sub_scores'];
            }
            if ($full) {
                if (array_key_exists('strengths', $entry)) {
                    $item['strengths'] = $entry['strengths'];
                }
                if (array_key_exists('weaknesses', $entry)) {
                    $item['weaknesses'] = $entry['weaknesses'];
                }
            }

            $out[$key] = $item;
        }

        return $out;
    }

    /** الفئات الأربع للفجوات — مفاتيح ثابتة دائماً (T096). */
    private function gapCategories(array $result): array
    {
        $gaps = is_array($result['gap_analysis'] ?? null) ? $result['gap_analysis'] : [];

        return [
            'technical_gaps' => $gaps['technical_gaps'] ?? [],
            'market_gaps' => $gaps['market_gaps'] ?? [],
            'team_gaps' => $gaps['team_gaps'] ?? [],
            'documentation_gaps' => $gaps['documentation_gaps'] ?? [],
        ];
    }

    /** أبعاد ناقصة — من الأبعاد القياسية غير الموجودة في result (تقرير جزئي). */
    private function partialDimensions(array $result): array
    {
        $dimensions = is_array($result['dimensions'] ?? null) ? $result['dimensions'] : [];

        return array_values(array_filter(
            array_keys(config('ai.weights')),
            static fn (string $key) => ! array_key_exists($key, $dimensions),
        ));
    }

    /**
     * مهارات مطلوبة: موجودة (من فريق المشروع) مقابل ناقصة — T100.
     *
     * @return array{has_team_data: bool, existing_skills: list<string>, missing_skills: list<string>, warning: string|null}
     */
    private function teamMeta(array $result, Project $project): array
    {
        $requiredSkills = array_values((array) ($result['required_skills'] ?? []));
        $team = is_array($project->team) ? $project->team : [];
        $hasTeamData = $team !== [];

        if (! $hasTeamData) {
            return [
                'has_team_data' => false,
                'existing_skills' => [],
                'missing_skills' => $requiredSkills,
                'warning' => self::TEAM_WARNING,
            ];
        }

        $teamRoles = array_map(
            static fn (mixed $member): string => strtolower((string) (is_array($member) ? ($member['role'] ?? '') : '')),
            $team,
        );

        $existing = [];
        $missing = [];

        foreach ($requiredSkills as $skill) {
            if ($this->teamHasSkill($teamRoles, $skill)) {
                $existing[] = $skill;
            } else {
                $missing[] = $skill;
            }
        }

        return [
            'has_team_data' => true,
            'existing_skills' => $existing,
            'missing_skills' => $missing,
            'warning' => null,
        ];
    }

    /**
     * مطابقة مرنة بين مهارة مطلوبة ودور عضو الفريق (تطبيع + تطابق جُزئي).
     *
     * @param  list<string>  $teamRoles
     */
    private function teamHasSkill(array $teamRoles, string $skill): bool
    {
        $skillNorm = preg_replace('/[^a-z0-9]+/', '', strtolower($skill)) ?? '';

        if ($skillNorm === '') {
            return false;
        }

        foreach ($teamRoles as $role) {
            $roleNorm = preg_replace('/[^a-z0-9]+/', '', $role) ?? '';

            if ($roleNorm === '') {
                continue;
            }

            if ($roleNorm === $skillNorm
                || str_contains($roleNorm, $skillNorm)
                || str_contains($skillNorm, $roleNorm)) {
                return true;
            }
        }

        return false;
    }

    private function pdfUrl(Project $project, Evaluation $evaluation): string
    {
        $lang = app()->getLocale() === 'en' ? 'en' : 'ar';

        return "/api/projects/{$project->id}/evaluations/{$evaluation->id}/report?lang={$lang}";
    }
}
