<?php

namespace App\Ai\Mcp;

/**
 * استجابة JSON-RPC 2.0 داخلية (plan.md §1.4 — Sub-Agent → Orchestrator).
 *
 *   النجاح: { "jsonrpc": "2.0", "id": 7, "result": { "score": 72.4, "sub_scores": {...}, ... } }
 *   الخطأ:  { "jsonrpc": "2.0", "id": 7, "error": { "code": -32001, "message": "provider_failure", "data": {...} } }
 *
 * @immutable
 */
final class McpResponse
{
    public const JSONRPC_VERSION = '2.0';

    /**
     * @param  mixed  $result  نتيجة ناجحة (مصفوفة مخطط البُعد مثلاً)
     */
    public function __construct(
        public readonly mixed $result = null,
        public readonly int|string|null $id = null,
        public readonly ?McpError $error = null,
        public readonly string $jsonrpc = self::JSONRPC_VERSION,
    ) {
    }

    public function isError(): bool
    {
        return $this->error !== null;
    }

    public function isSuccess(): bool
    {
        return $this->error === null;
    }

    /**
     * @return array{jsonrpc: string, result?: mixed, error?: array<string, mixed>, id?: int|string}
     */
    public function toArray(): array
    {
        $payload = [
            'jsonrpc' => $this->jsonrpc,
        ];

        if ($this->id !== null) {
            $payload['id'] = $this->id;
        }

        if ($this->error !== null) {
            $payload['error'] = $this->error->toArray();
        } else {
            $payload['result'] = $this->result;
        }

        return $payload;
    }

    public static function success(mixed $result, int|string|null $id = null): self
    {
        return new self(result: $result, id: $id);
    }

    public static function error(McpError $error, int|string|null $id = null): self
    {
        return new self(id: $id, error: $error);
    }

    /**
     * بناء استجابة من مصفوفة (تُستخدم عند تفحص السجلات أو في الاختبارات).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $id = isset($data['id']) && (is_int($data['id']) || is_string($data['id'])) ? $data['id'] : null;

        if (isset($data['error']) && is_array($data['error'])) {
            return new self(id: $id, error: McpError::fromArray($data['error']));
        }

        return new self(result: $data['result'] ?? null, id: $id);
    }
}
