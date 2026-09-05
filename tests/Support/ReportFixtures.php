<?php

namespace Tests\Support;

use App\Enums\EvaluationStatus;
use App\Enums\InterestStatus;
use App\Enums\InterestType;
use App\Models\Evaluation;
use App\Models\Interest;
use App\Models\Project;
use App\Models\User;

/**
 * تجهيزات EPIC-05 (تقرير AI) — مصفوفة إفصاح + فجوات + مهارات + PDF.
 * تُستخدم في tests/Feature/Reports/*.
 *
 * بنية result مطابقة مخطط §5.4.6.3 (EvaluationReport::toArray):
 * dimensions بمعايير فرعية ونقاط قوة/ضعف · gap_analysis بأربع فئات ·
 * recommendations بثلاثة آفاق · required_skills مسطّحة.
 */
trait ReportFixtures
{
    /**
     * تقييم مكتمل بكل الأبعاد (5/5) — لمصفوفة الإفصاح والتحليل الكامل.
     */
    protected function makeCompletedEvaluation(Project $project, array $overrides = []): Evaluation
    {
        $result = array_merge([
            'schema_version' => '1.0',
            'overall_score' => 72.4,
            'dimensions' => [
                'technical_quality' => [
                    'score' => 71.2,
                    'sub_scores' => ['code_structure' => 78.0, 'architecture' => 74.0, 'testing' => 60.0, 'ci_cd' => 55.0, 'documentation' => 82.0],
                    'strengths' => ['بنية معيارية واضحة'],
                    'weaknesses' => ['لا توجد اختبارات آلية'],
                ],
                'innovation' => [
                    'score' => 80.0,
                    'sub_scores' => ['novelty' => 82.0, 'problem_originality' => 78.0, 'approach_creativity' => 80.0],
                    'strengths' => ['فكرة مميزة وسوق واع'],
                    'weaknesses' => [],
                ],
                'market_viability' => [
                    'score' => 55.0,
                    'sub_scores' => ['problem_clarity' => 70.0, 'market_size_estimation' => 50.0, 'business_model_potential' => 40.0, 'competitive_awareness' => 60.0],
                    'strengths' => [],
                    'weaknesses' => ['لا نموذج إيرادات موصوف'],
                ],
                'team_completeness' => [
                    'score' => 62.0,
                    'sub_scores' => ['skill_diversity' => 60.0, 'relevant_experience' => 65.0, 'role_clarity' => 60.0],
                    'strengths' => [],
                    'weaknesses' => ['لا مسوّق في الفريق'],
                ],
                'documentation' => [
                    'score' => 88.0,
                    'sub_scores' => ['project_description' => 90.0, 'objectives_clarity' => 85.0, 'supporting_docs_quality' => 88.0, 'roadmap_clarity' => 86.0],
                    'strengths' => ['توثيق شامل ومحدّث'],
                    'weaknesses' => [],
                ],
            ],
            'gap_analysis' => [
                'technical_gaps' => ['لا توجد اختبارات آلية'],
                'market_gaps' => ['لا نموذج إيرادات موصوف'],
                'team_gaps' => ['لا مسوّق في الفريق'],
                'documentation_gaps' => [],
            ],
            'recommendations' => [
                'immediate' => ['أضف README تقنياً بخطوات التشغيل'],
                'short_term' => ['حدد نموذج الإيرادات في صفحة المشروع'],
                'long_term' => ['أنشئ CI/CD مع تغطية اختبارات'],
            ],
            'required_skills' => ['UI/UX Designer', 'Digital Marketing', 'Backend Developer'],
            'warnings' => ['معلومات الفريق غير كافية — قد يؤثر على دقة بُعد اكتمال الفريق'],
        ], $overrides);

        return Evaluation::create([
            'project_id' => $project->id,
            'version' => 1,
            'status' => EvaluationStatus::COMPLETED,
            'overall_score' => $result['overall_score'],
            'confidence_score' => 78.5,
            'result' => $result,
            'model_used' => 'openai',
            'completed_at' => now(),
        ]);
    }

    /**
     * تقييم جزئي (3/5) — market_viability و team_completeness ناقصتان
     * (الرادار يعرض المكتمل فقط — T090).
     */
    protected function makePartialEvaluation(Project $project): Evaluation
    {
        $result = [
            'schema_version' => '1.0',
            'overall_score' => 68.0,
            'dimensions' => [
                'technical_quality' => [
                    'score' => 70.0,
                    'sub_scores' => ['code_structure' => 72.0, 'architecture' => 70.0, 'testing' => 55.0, 'ci_cd' => 60.0, 'documentation' => 80.0],
                    'strengths' => [],
                    'weaknesses' => [],
                ],
                'innovation' => [
                    'score' => 82.0,
                    'sub_scores' => ['novelty' => 84.0, 'problem_originality' => 80.0, 'approach_creativity' => 82.0],
                    'strengths' => [],
                    'weaknesses' => [],
                ],
                'documentation' => [
                    'score' => 86.0,
                    'sub_scores' => ['project_description' => 88.0, 'objectives_clarity' => 84.0, 'supporting_docs_quality' => 86.0, 'roadmap_clarity' => 85.0],
                    'strengths' => [],
                    'weaknesses' => [],
                ],
            ],
            'gap_analysis' => [
                'technical_gaps' => [],
                'market_gaps' => ['لم يُقيَّم البعد السوقي — ناقص'],
                'team_gaps' => ['لم يُقيَّم بُعد الفريق — ناقص'],
                'documentation_gaps' => [],
            ],
            'recommendations' => [
                'immediate' => ['أكمل بيانات السوق والفريق لإعادة التقييم'],
                'short_term' => [],
                'long_term' => [],
            ],
            'required_skills' => ['UI/UX Designer'],
            'warnings' => ['اكتملت 3 من 5 أبعاد ضمن السقف الزمني'],
            'partial_dimensions' => ['market_viability', 'team_completeness'],
        ];

        return Evaluation::create([
            'project_id' => $project->id,
            'version' => 1,
            'status' => EvaluationStatus::PARTIAL,
            'overall_score' => 68.0,
            'confidence_score' => 60.0,
            'result' => $result,
            'model_used' => 'openai',
            'completed_at' => now(),
        ]);
    }

    /** اتفاق نشط: طلب اهتمام مقبول (مصدر "الاتفاق" المرحلي في Sprint 2). */
    protected function acceptInterest(Project $project, User $investor): Interest
    {
        return Interest::create([
            'project_id' => $project->id,
            'investor_id' => $investor->id,
            'interest_type' => InterestType::INVESTMENT,
            'message' => 'مهتم بالاستثمار',
            'status' => InterestStatus::ACCEPTED,
            'accepted_at' => now(),
        ]);
    }
}
