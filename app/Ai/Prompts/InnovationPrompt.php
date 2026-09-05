<?php

namespace App\Ai\Prompts;

/**
 * Prompt بُعد الإبداع والتميز (Innovation — plan.md §1.1 / §3.3).
 * المعايير: novelty · problem_originality · approach_creativity.
 */
final class InnovationPrompt extends AbstractPrompt
{
    public function dimension(): string
    {
        return 'innovation';
    }

    protected function dimensionLabel(): string
    {
        return 'الإبداع والتميز';
    }

    protected function criteria(): array
    {
        return [
            'novelty' => 'درجة حداثة الفكرة مقارنة بالحلول والمنتجات الموجودة في السوق',
            'problem_originality' => 'أصالة المشكلة التي يعالجها المشروع وأهميتها',
            'approach_creativity' => 'إبداع المنهجية أو النهج التقني المتبع لحل المشكلة',
        ];
    }
}
