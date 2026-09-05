<?php

namespace App\Support;

use App\Enums\EvaluationStatus;
use App\Exceptions\Ai\EvaluationCooldownException;
use App\Exceptions\Ai\EvaluationInProgressException;
use App\Exceptions\Ai\EvaluationNotFailedException;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * آلة حالات التقييم (data-model.md §2.4 — SRS-DB-05 / US-016).
 *
 * الرسم المعتمد:
 *   pending → processing → completed | partial | failed
 *   failed   → processing   (إعادة محاولة — Retry)
 *   partial  → processing   (إعادة محاولة / إكمال الناقص بعد 1h)
 *   completed → processing  (إعادة تقييم بعد 24h بالضبط)
 *
 * الحراس (Guards):
 *   - pending/failed → processing : لا يوجد تقييم `processing` نشط لنفس المشروع (EvaluationInProgressException).
 *   - completed → processing      : last_evaluation_at + 24h ≤ now وإلا EvaluationCooldownException.
 *   - partial → processing        : آخر تقييم + 1h وإلا EvaluationCooldownException.
 *   - retry()                      : فقط من failed|partial وإلا EvaluationNotFailedException.
 *
 * @final
 */
final class EvaluationStateMachine
{
    private const TRANSITIONS = [
        EvaluationStatus::PENDING->value => [
            EvaluationStatus::PROCESSING->value => 'pending_to_processing',
        ],
        EvaluationStatus::PROCESSING->value => [
            EvaluationStatus::COMPLETED->value => 'processing_to_completed',
            EvaluationStatus::PARTIAL->value => 'processing_to_partial',
            EvaluationStatus::FAILED->value => 'processing_to_failed',
        ],
        EvaluationStatus::FAILED->value => [
            EvaluationStatus::PROCESSING->value => 'failed_to_processing',
        ],
        EvaluationStatus::PARTIAL->value => [
            EvaluationStatus::PROCESSING->value => 'partial_to_processing',
        ],
        EvaluationStatus::COMPLETED->value => [
            EvaluationStatus::PROCESSING->value => 'completed_to_processing',
        ],
    ];

    /**
     * @return list<string> أسماء الحالات المسموح الانتقال منها
     */
    public function allowedTransitions(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    /**
     * هل الانتقال معرّف في الرسم؟
     */
    public function canTransition(EvaluationStatus $from, EvaluationStatus $to): bool
    {
        return isset(self::TRANSITIONS[$from->value][$to->value]);
    }

    /**
     * يرمي InvalidArgumentException إذا كان الانتقال غير معرّف في الرسم.
     */
    public function assertCanTransition(EvaluationStatus $from, EvaluationStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid evaluation state transition: %s → %s',
                $from->value,
                $to->value
            ));
        }
    }

    /**
     * ينفّذ الانتقال مع فرض الحارس المناسب (Guard).
     *
     * @param  array{
     *     now?: \DateTimeInterface,
     *     last_evaluation_at?: \DateTimeInterface|null,
     *     has_active_processing?: bool,
     *     re_evaluation_hours?: int,
     *     partial_retry_hours?: int,
     *     project_id?: int|null,
     * }  $context
     */
    public function transition(EvaluationStatus $from, EvaluationStatus $to, array $context = []): EvaluationStatus
    {
        $this->assertCanTransition($from, $to);
        $this->assertGuard($from, $to, $context);

        return $to;
    }

    /**
     * مسار إعادة المحاولة: فقط من `failed` أو `partial` → `processing`.
     *
     * @param  array<string, mixed>  $context  نفس سياق transition()
     *
     * @throws EvaluationNotFailedException إذا لم تكن الحالة الحالية failed|partial.
     */
    public function retry(EvaluationStatus $from, array $context = []): EvaluationStatus
    {
        if ($from !== EvaluationStatus::FAILED && $from !== EvaluationStatus::PARTIAL) {
            throw new EvaluationNotFailedException($from->value);
        }

        return $this->transition($from, EvaluationStatus::PROCESSING, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function assertGuard(EvaluationStatus $from, EvaluationStatus $to, array $context): void
    {
        // الانتقالات نحو الحالات النهائية (completed/partial/failed) لا تملك حارساً
        // على مستوى آلة الحالات — تُفرض داخل Orchestrator/المُتحقق (سقف 180s + تحقق المخرجات).
        if ($to !== EvaluationStatus::PROCESSING) {
            return;
        }

        if ($from === EvaluationStatus::COMPLETED) {
            $this->assertCooldown($context, (int) ($context['re_evaluation_hours'] ?? config('ai.re_evaluation_cache_hours', 24)));

            return;
        }

        if ($from === EvaluationStatus::PARTIAL) {
            $this->assertCooldown($context, (int) ($context['partial_retry_hours'] ?? config('ai.partial_retry_hours', 1)));

            return;
        }

        // pending → processing  و  failed → processing : منع التقييم المتزامن.
        $this->assertNoActiveProcessing($context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function assertNoActiveProcessing(array $context): void
    {
        if (($context['has_active_processing'] ?? false) === true) {
            throw new EvaluationInProgressException(
                isset($context['project_id']) && is_numeric($context['project_id'])
                    ? (int) $context['project_id']
                    : null
            );
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function assertCooldown(array $context, int $hours): void
    {
        $last = $context['last_evaluation_at'] ?? null;
        $now = $context['now'] ?? new DateTimeImmutable();

        if (! $last instanceof DateTimeInterface) {
            return; // لا تقييم سابق → لا فترة هدوء
        }

        $nextAllowed = (clone $last)->modify(sprintf('+%d hours', $hours));

        if (! $nextAllowed instanceof DateTimeInterface) {
            return;
        }

        if ($now < $nextAllowed) {
            $remaining = (int) max(1, $nextAllowed->getTimestamp() - $now->getTimestamp());

            throw new EvaluationCooldownException($remaining, $nextAllowed);
        }
    }
}
