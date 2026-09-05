<?php

namespace App\Ai\Prompts;

/**
 * Prompt بُعد التوثيق والبيانات (Documentation — plan.md §1.1 / §3.3).
 * المعايير: project_description · objectives_clarity · supporting_docs_quality · roadmap_clarity.
 */
final class DocumentationPrompt extends AbstractPrompt
{
    public function dimension(): string
    {
        return 'documentation';
    }

    protected function dimensionLabel(): string
    {
        return 'التوثيق والبيانات';
    }

    protected function criteria(): array
    {
        return [
            'project_description' => 'جودة وصف المشروع واكتماله ووضوح رسالته',
            'objectives_clarity' => 'وضوح الأهداف والنتائج المرجوة ومؤشرات النجاح',
            'supporting_docs_quality' => 'جودة المستندات الداعمة (مخططات، دراسات، نماذج أولية، ملفات PDF)',
            'roadmap_clarity' => 'وضوح خارطة الطريق والمراحل الزمنية والمعالم الرئيسية',
        ];
    }
}
