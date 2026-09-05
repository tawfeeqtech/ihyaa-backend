<?php

namespace App\Exceptions\Ai;

use Exception;
use Throwable;

/**
 * استُنفدت كل المحاولات على المزودين معاً (plan.md §3.2 — FR-222 / SRS-AI-F04).
 * يُرمى من FallbackManager بعد فشل الأساسي والاحتياطي → حدث AllAiProvidersFailed → تنبيه المشرف.
 */
class AllProvidersFailedException extends Exception
{
    /**
     * @param  list<ProviderException>  $failures  كل إخفاقات المزودين (للتسجيل/المعايرة)
     */
    public function __construct(
        public readonly array $failures = [],
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : 'All AI providers failed after exhausting every attempt.',
            $code,
            $previous
        );
    }

    /**
     * @return list<ProviderException>
     */
    public function failures(): array
    {
        return $this->failures;
    }
}
