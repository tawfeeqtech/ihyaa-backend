<?php

namespace App\Http\Resources;

use App\Models\Project;
use App\Support\ScoreFormatter;

/**
 * بطاقة اقتراح للمستثمر — US-056 · contract §2 (suggestions) · T081.
 *
 * Level 1 فقط (مبدأ I): العنوان/الصورة/القطاع/الدرجة/الحالة — لا درجات أبعاد،
 * لا وصف كامل، لا owner. badge التفاعل يمرره المتصل (SuggestionMatcher::engagementBadges).
 */
class SuggestionResource
{
    /**
     * @param  string|null  $engagementBadge  'sent' | 'saved' | null
     */
    public static function make(Project $project, ?string $engagementBadge): array
    {
        return [
            'id' => $project->id,
            'title' => $project->title,
            'category' => $project->category?->name(),
            'status' => $project->status?->value,
            'ai_score' => ScoreFormatter::format($project->ai_score),
            'budget_min' => ScoreFormatter::format($project->budget_min),
            'budget_max' => ScoreFormatter::format($project->budget_max),
            'cover_image_url' => $project->coverUrl(),
            'engagement_badge' => $engagementBadge,
        ];
    }
}
