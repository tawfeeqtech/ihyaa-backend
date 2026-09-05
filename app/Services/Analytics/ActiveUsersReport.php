<?php

namespace App\Services\Analytics;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * EPIC-12 · US-062 — المستخدمون النشطون (آخر 7 أيام).
 *
 * يرجع دائماً 7 صفوف (آخر 7 أيام شاملة اليوم) بترتيب تصاعدي للتاريخ —
 * الأيام الفارغة تُرسم بـ 0 (لا فجوات — admin-api.md §1 · US-062 س2).
 *
 * التعريف المثبّت لـ"المستخدم النشط": سجّل دخولاً أو نفّذ إجراءً مصادقاً خلال
 * اليوم — يُسجَّل في users.last_active_at (يُحدَّث عند تسجيل الدخول، ويُحدّثه
 * Middleware UpdateLastActiveAt على الإجراءات المصادَقة).
 */
class ActiveUsersReport
{
    /**
     * 7 صفوف دائماً: { date: Y-m-d, count } — COUNT(*) من users.last_active_at
     * لكل يوم (صف واحد لكل مستخدم = COUNT(DISTINCT user_id) لأنه عمود وحيد).
     *
     * @return array<int, array{date: string, count: int}>
     */
    public function last7Days(): array
    {
        $days = $this->dayRange();

        $start = Carbon::parse($days->first())->startOfDay();
        $endExclusive = Carbon::parse($days->last())->startOfDay()->addDay();

        $counts = User::query()
            ->whereNotNull('last_active_at')
            ->where('last_active_at', '>=', $start)
            ->where('last_active_at', '<', $endExclusive)
            ->selectRaw('DATE(last_active_at) AS date, COUNT(*) AS count')
            ->groupBy('date')
            ->pluck('count', 'date');

        return $days
            ->map(fn (string $date) => [
                'date' => $date,
                'count' => (int) ($counts[$date] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * آخر 7 أيام شاملة اليوم (أقدم ← أحدث).
     *
     * @return Collection<int, string> — تواريخ Y-m-d
     */
    private function dayRange(): Collection
    {
        return collect(range(6, 0))
            ->map(fn (int $i) => now()->subDays($i)->toDateString());
    }
}
