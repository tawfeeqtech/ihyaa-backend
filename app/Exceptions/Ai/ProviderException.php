<?php

namespace App\Exceptions\Ai;

use Exception;
use Throwable;

/**
 * فشل مزود AI واحد (plan.md §3.2 — SRS-AI-F01/F02/F04).
 * مشغلات التحويل (Fallback): خطأ شبكة، 5xx، مهلة 45s، JSON غير صالح، درجات خارج 0-100.
 */
class ProviderException extends Exception
{
    /**
     * @param  string  $provider  'openai' | 'claude'
     * @param  int  $attempt  رقم المحاولة (1/2/3 — جدول 0/3/9s)
     * @param  string|null  $reason  timeout | 5xx | network | invalid_json | out_of_range | rate_limited
     * @param  array<string, mixed>  $context  بيانات سياقية إضافية (بدون محتوى المشروع — المبدأ V)
     */
    public function __construct(
        public readonly string $provider,
        string $message = '',
        public readonly int $attempt = 1,
        public readonly ?string $reason = null,
        public readonly array $context = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : sprintf('AI provider [%s] request failed.', $provider),
            $code,
            $previous
        );
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
