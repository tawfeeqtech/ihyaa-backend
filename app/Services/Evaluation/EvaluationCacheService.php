<?php

namespace App\Services\Evaluation;

use Illuminate\Support\Facades\Cache;

/**
 * مفاتيح Redis للتقييم — plan.md §4.2/§4.3 (SRS-AI-C01..C03).
 *
 * | المفتاح | TTL | المحتوى | الغرض |
 * |---|---|---|---|
 * | `evaluation:result:{evaluation_id}` | 24h | نتيجة JSON كاملة | إرجاع التقييم المخزَّن دون قراءة DB ثقيلة |
 * | `evaluation:cooldown:{project_id}` | متغير = المدة حتى التقييم التالي | `{"next_allowed_at","remaining_seconds"}` | عرض المؤقّت "التقييم التالي في X" |
 * | `evaluation:lock:{project_id}` | 30s | — | قفل ذري ضد التقييم المتزامن (US-024-S4) |
 *
 * قواعد الإبطال (§4.3): اكتمال تقييم جديد ← storeResult + storeCooldown ·
 * فشل ← لا cooldown (forgetCooldown) · تأكيد إعادة التقييم ← forgetCooldown قبل البدء.
 *
 * @final
 */
final class EvaluationCacheService
{
    public const RESULT_KEY = 'evaluation:result:%d';

    public const COOLDOWN_KEY = 'evaluation:cooldown:%d';

    public const LOCK_KEY = 'evaluation:lock:%d';

    /** TTL الافتراضي لنتيجة تقييم واحدة (ساعات) — plan.md §4.2. */
    public const RESULT_TTL_HOURS = 24;

    public function resultKey(int $evaluationId): string
    {
        return sprintf(self::RESULT_KEY, $evaluationId);
    }

    public function cooldownKey(int $projectId): string
    {
        return sprintf(self::COOLDOWN_KEY, $projectId);
    }

    public function lockKey(int $projectId): string
    {
        return sprintf(self::LOCK_KEY, $projectId);
    }

    /**
     * تخزين نتيجة تقييم كاملة.
     *
     * @param  array<string, mixed>  $payload  مخطط result JSON (data-model.md §2.2)
     * @param  int|null  $ttlSeconds  ثوانٍ؛ null = 24 ساعة
     */
    public function storeResult(int $evaluationId, array $payload, ?int $ttlSeconds = null): void
    {
        Cache::put(
            $this->resultKey($evaluationId),
            $payload,
            $ttlSeconds ?? now()->addHours(self::RESULT_TTL_HOURS),
        );
    }

    /**
     * قراءة نتيجة التقييم المخزَّنة (أو null).
     *
     * @return array<string, mixed>|null
     */
    public function cachedResult(int $evaluationId): ?array
    {
        $cached = Cache::get($this->resultKey($evaluationId));

        return is_array($cached) ? $cached : null;
    }

    public function forgetResult(int $evaluationId): void
    {
        Cache::forget($this->resultKey($evaluationId));
    }

    /**
     * تخزين عداد الهدوء لمشروع — plan.md §4.2.
     *
     * @param  array<string, mixed>  $payload  `{"next_allowed_at": ISO, "remaining_seconds": int}`
     * @param  int  $ttlSeconds  المدة المتبقية حتى التقييم التالي
     */
    public function storeCooldown(int $projectId, array $payload, int $ttlSeconds): void
    {
        Cache::put(
            $this->cooldownKey($projectId),
            $payload,
            max(1, $ttlSeconds),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cachedCooldown(int $projectId): ?array
    {
        $cached = Cache::get($this->cooldownKey($projectId));

        return is_array($cached) ? $cached : null;
    }

    public function forgetCooldown(int $projectId): void
    {
        Cache::forget($this->cooldownKey($projectId));
    }

    /**
     * إبطال كل مفاتيح المشروع — يُستدعى عند تأكيد إعادة التقييم (§4.3: لا قبل التأكيد).
     */
    public function forgetProject(int $projectId): void
    {
        $this->forgetCooldown($projectId);
    }
}
