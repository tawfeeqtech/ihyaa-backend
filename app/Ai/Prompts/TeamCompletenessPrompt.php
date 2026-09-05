<?php

namespace App\Ai\Prompts;

/**
 * Prompt بُعد اكتمال الفريق (Team Completeness — plan.md §1.1 / §3.3).
 * المعايير: skill_diversity · relevant_experience · role_clarity.
 */
final class TeamCompletenessPrompt extends AbstractPrompt
{
    public function dimension(): string
    {
        return 'team_completeness';
    }

    protected function dimensionLabel(): string
    {
        return 'اكتمال الفريق';
    }

    protected function criteria(): array
    {
        return [
            'skill_diversity' => 'تنوع المهارات والتخصصات المغطاة في الفريق',
            'relevant_experience' => 'الخبرة العملية ذات الصلة بمجال المشروع',
            'role_clarity' => 'وضوح الأدوار والمسؤوليات داخل الفريق',
        ];
    }
}
