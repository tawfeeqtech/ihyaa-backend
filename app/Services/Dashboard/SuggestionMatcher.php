<?php

namespace App\Services\Dashboard;

use App\Enums\InterestStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * اقتراحات المستثمر — US-056 · data-model §4 (T080).
 *
 * مطابقة بسيطة بلا تعلّم آلي (نطاق v1.1):
 *   - مطابقة قطاع: تداخل `preferred_sectors` مع (slug/اسم التصنيف) أو tags المشروع.
 *   - ترتيب: تطابق القطاع (أساسي) ← درجة AI تنازلياً ← الأحدث.
 *   - حد: 10 اقتراحات (SRS-F11-01).
 * الملف الفارغ (profile_complete=false) → fallback بأفضل الدرجات — لا شاشة فارغة.
 * المشاريع التي تواصل معها المستثمر تبقى مع badge (في الـ Resource) — لا استبعاد.
 */
class SuggestionMatcher
{
    public const DEFAULT_LIMIT = 10;

    public const CANDIDATE_LIMIT = 200;      // سقف أمان (المتوقع ~50 — data-model §4.1)

    /**
     * المشاريع المقترحة للمستثمر، مرتبة حسب أولوية القطاع ← الدرجة ← الحداثة.
     *
     * @return Collection<int, Project>
     */
    public function match(User $investor, int $limit = self::DEFAULT_LIMIT): Collection
    {
        $preferred = $this->normalizeSectors($investor->preferred_sectors);

        $candidates = Project::query()
            ->with(['category', 'files' => fn ($q) => $q->where('type', 'image')])
            ->published()
            // لا نقترح مشاريع المستثمر نفسه (US-056/1 · contract §2).
            ->where('user_id', '!=', $investor->id)
            ->orderByDesc('created_at')
            ->limit(self::CANDIDATE_LIMIT)
            ->get();

        $ranked = $candidates->all();

        usort($ranked, function (Project $a, Project $b) use ($preferred): int {
            $aKey = $this->rankKey($a, $preferred);
            $bKey = $this->rankKey($b, $preferred);

            foreach (['sector', 'score', 'recency'] as $field) {
                $cmp = $bKey[$field] <=> $aKey[$field];      // تنازلي
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return 0;
        });

        return collect($ranked)->slice(0, $limit)->values();
    }

    /**
     * badge التفاعل للمقترحات — خريطة project_id → 'sent' | 'saved'.
     * أولوية 'sent' (طلب نشط) على 'saved' — contract §2 (US-056/5).
     *
     * @return array<int, string>
     */
    public function engagementBadges(User $investor): array
    {
        $sent = $investor->interestsSent()
            ->where('status', '!=', InterestStatus::CANCELLED->value)
            ->pluck('project_id');

        $saved = $investor->savedProjects()->pluck('project_id');

        $badges = [];

        foreach ($sent as $projectId) {
            $badges[(int) $projectId] = 'sent';
        }

        foreach ($saved as $projectId) {
            $badges[(int) $projectId] ??= 'saved';
        }

        return $badges;
    }

    /**
     * @return array{sector:int, score:float, recency:int}
     */
    private function rankKey(Project $project, array $preferred): array
    {
        return [
            'sector' => $this->sectorMatch($project, $preferred) ? 1 : 0,
            'score' => $project->ai_score ?? -1.0,
            'recency' => $project->created_at?->getTimestamp() ?? 0,
        ];
    }

    /**
     * sectorMatch: in_array(تصنيف المشروع, $preferred) أو تداخل tags غير فارغ
     * (data-model §4.2 — بلا ML). التصنيف يُقارن بـ slug + الاسمين (عربي/إنجليزي)
     * لتحمّل بيانات الـ factory/seed التي تحمل الأسماء العربية.
     *
     * @param array<int, string> $preferred
     */
    private function sectorMatch(Project $project, array $preferred): bool
    {
        if ($preferred === []) {
            return false;
        }

        $category = $project->category;

        $categoryKeys = array_filter([
            $category?->slug,
            $category?->name_ar,
            $category?->name_en,
        ]);

        if (count(array_intersect($categoryKeys, $preferred)) > 0) {
            return true;
        }

        $tags = is_array($project->tags) ? $project->tags : [];

        return count(array_intersect($tags, $preferred)) > 0;
    }

    /**
     * تطبيع `preferred_sectors` — يتعامل مع null / JSON مالِف / مصفوفة
     * (data-model §4.2) بلا كسر: أي شيء غير مصفوفة → [].
     *
     * @return array<int, string>
     */
    private function normalizeSectors(mixed $sectors): array
    {
        if (is_array($sectors)) {
            return array_values(array_filter(array_map('strval', $sectors), fn ($s) => $s !== ''));
        }

        if (is_string($sectors) && $sectors !== '') {
            $decoded = json_decode($sectors, true);

            return is_array($decoded) ? array_values($decoded) : [];
        }

        return [];
    }
}
