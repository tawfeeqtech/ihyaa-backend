<?php

namespace App\Ai\Mcp;

/**
 * جسم الخطأ JSON-RPC 2.0 (plan.md §1.4).
 *
 *   { "code": -32001, "message": "provider_failure", "data": { "provider": "openai", "attempt": 2 } }
 *
 * @immutable
 */
final class McpError
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;
    public const PROVIDER_FAILURE = -32001;

    /**
     * @param  int  $code  كود خطأ JSON-RPC (ثوابت أعلاه)
     * @param  string  $message  رمز خطأ قصير (provider_failure | invalid_request | ...)
     * @param  array<string, mixed>  $data  بيانات سياقية (بدون محتوى المشروع — المبدأ V)
     */
    public function __construct(
        public readonly int $code,
        public readonly string $message,
        public readonly array $data = [],
    ) {
    }

    /**
     * @return array{code: int, message: string, data: array<string, mixed>}
     */
    public function toArray(): array
    {
        $payload = [
            'code' => $this->code,
            'message' => $this->message,
        ];

        if ($this->data !== []) {
            $payload['data'] = $this->data;
        }

        return $payload;
    }

    public static function fromArray(array $error): self
    {
        return new self(
            (int) ($error['code'] ?? self::INTERNAL_ERROR),
            (string) ($error['message'] ?? 'internal_error'),
            (array) ($error['data'] ?? []),
        );
    }
}
