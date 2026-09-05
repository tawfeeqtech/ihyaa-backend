<?php

namespace App\Services\AI;

use App\Enums\EvaluationStatus;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;

/**
 * T105/T106 — اختيار المنافسين لمقارنة المشروع (US-080 · SRS-API-42 comparison).
 *
 * منافسون = مشاريع من نفس الفئة، غير محذوفة/غير ذاتية، ولها تقييم مكتمل.
 * الترتيب: تقاطع الوسوم (tags) تنازلياً ثم قرب درجة ai_score تصاعدياً، مع سقف
 * max_competitors (5). عندما يكون العدد < min_competitors (3) تُضاف insufficient_data_note.
 */
class CompetitorSelector
{
    /**
     * @return array{competitors: list<array<string, mixed>>, count: int, insufficient_data_note: bool}
     */
    public function select(Project $project, int $limit = 5): array
    {
        $limit = $limit ?: (int) config('ai-agent.comparison.max_competitors', 5);

        $candidates = Project::query()
            ->where('category_id', $project->category_id)
            ->whereKeyNot($project->getKey())
            ->whereHas('evaluationHistory', function (Builder $q) {
                $q->where('status', EvaluationStatus::COMPLETED->value);
            })
            ->get(['id', 'title', 'tags', 'ai_score', 'description']);

        $projectTags = array_map('strtolower', (array) ($project->tags ?? []));

        $competitors = $candidates
            ->map(function (Project $candidate) use ($projectTags, $project) {
                $candidateTags = array_map('strtolower', (array) ($candidate->tags ?? []));
                $candidateScore = (float) ($candidate->ai_score ?? 0);
                $projectScore = (float) ($project->ai_score ?? 0);

                return [
                    'id' => (int) $candidate->id,
                    'title' => (string) $candidate->title,
                    'ai_score' => $candidateScore,
                    'tag_overlap' => count(array_intersect($projectTags, $candidateTags)),
                    '_score_diff' => abs($candidateScore - $projectScore),
                ];
            })
            ->sort(function (array $a, array $b) {
                // الترتيب: تقاطع الوسوم تنازلياً ← ثم قرب الدرجة تصاعدياً
                return $b['tag_overlap'] <=> $a['tag_overlap']
                    ?: $a['_score_diff'] <=> $b['_score_diff'];
            })
            ->values()
            ->take($limit)
            ->map(fn (array $item) => array_diff_key($item, ['_score_diff' => true]))
            ->values()
            ->all();

        return [
            'competitors' => $competitors,
            'count' => count($competitors),
            'insufficient_data_note' => count($competitors) < (int) config('ai-agent.comparison.min_competitors', 3),
        ];
    }
}
