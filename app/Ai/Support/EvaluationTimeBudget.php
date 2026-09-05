<?php

namespace App\Ai\Support;

use Illuminate\Support\Carbon;

/**
 * ميزانية وقت التقييم — plan.md §1.3 (SRS-AI-P02/P03 · سقف صلب 180s).
 *
 * تحكم إجمالي بزمن دورة التقييم (المهام المتزامنة + التصعيد) بحدّ أعلى 180 ثانية
 * (P95 < 120s والسقف 180s — plan.md §1.3/§2.2). تُستخدم في ConcurrentDispatcher:
 *  - `canLaunch()`: تُفحص قبل إطلاق كل مهمة — لا تُطلق مهمة بعد امتلاء الميزانية
 *    (يرفض المجمّع بخطأ مهلة واضح يُترجم إلى حالة failed — SRS-AI-F04).
 *  - `shouldStopEscalation()`: لطبقة التصعيد (FallbackManager) — لا تُطلق طلب
 *    مزوّد جديد قرب السقف (لن يُكمل ضمن الميزانية).
 *
 * الوقت يُقاس من لحظة الإنشاء (أو `start()`) — زمن إيجابي لكل حساب (max 0).
 *
 * @final
 */
final class EvaluationTimeBudget
{
    public const HARD_CEILING_SECONDS = 180;   // سقف صلب (plan.md §1.3 — SRS-AI-P02)

    public const SOFT_LIMIT_SECONDS = 120;      // هدف P95 (plan.md §2.2)

    public function __construct(
        private readonly int $ceilingSeconds = self::HARD_CEILING_SECONDS,
        private readonly ?Carbon $startedAt = null,
    ) {
    }

    /** إنشاء ميزانية تبدأ الآن بسقف محدد (افتراضياً 180s) */
    public static function start(int $ceilingSeconds = self::HARD_CEILING_SECONDS): self
    {
        return new self($ceilingSeconds, Carbon::now());
    }

    public function ceilingSeconds(): int
    {
        return $this->ceilingSeconds;
    }

    /** الوقت المنقضي بالثواني (≥ 0) منذ بدء الميزانية */
    public function elapsedSeconds(): float
    {
        if ($this->startedAt === null) {
            return 0.0;
        }

        return max(0.0, (float) $this->startedAt->diffInSeconds(now()));
    }

    /** الوقت المتبقي بالثواني (≥ 0) */
    public function remainingSeconds(): float
    {
        return max(0.0, $this->ceilingSeconds - $this->elapsedSeconds());
    }

    /** هل ما زال يمكن إطلاق مهمة جديدة ضمن الميزانية؟ */
    public function canLaunch(): bool
    {
        return $this->remainingSeconds() > 0;
    }

    /**
     * هل نتوقف عن التصعيد (إطلاق طلب مزوّد جديد)؟
     * صحيح عندما لا يكفي الوقت المتبقي لطلب كامل (requestTimeout) — لن يُكمل ضمن الميزانية.
     */
    public function shouldStopEscalation(float $requestTimeout = 45.0): bool
    {
        return $this->remainingSeconds() <= $requestTimeout;
    }
}
