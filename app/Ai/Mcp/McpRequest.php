<?php

namespace App\Ai\Mcp;

/**
 * طلب JSON-RPC 2.0 داخلي (plan.md §1.4 — Orchestrator → McpRouter → Sub-Agent).
 *
 *   { "jsonrpc": "2.0", "id": 7, "method": "agent.technical_quality.evaluate",
 *     "params": { "evaluation_id": 42, "project_id": 17, "language": "ar", "context": {...}, "schema_version": "1.0" } }
 *
 * @immutable
 */
final class McpRequest
{
    public const JSONRPC_VERSION = '2.0';

    /**
     * @param  string  $method  e.g. "agent.technical_quality.evaluate"
     * @param  array<string, mixed>  $params
     * @param  int|string|null  $id  معرّف الطلب = evaluation_id × 10 + dimension_index لربط الاستجابات بلا حالة مشتركة
     */
    public function __construct(
        public readonly string $method,
        public readonly array $params = [],
        public readonly int|string|null $id = null,
        public readonly string $jsonrpc = self::JSONRPC_VERSION,
    ) {
    }

    /**
     * @return array{jsonrpc: string, method: string, params: array<string, mixed>, id?: int|string}
     */
    public function toArray(): array
    {
        $payload = [
            'jsonrpc' => $this->jsonrpc,
            'method' => $this->method,
            'params' => $this->params,
        ];

        if ($this->id !== null) {
            $payload['id'] = $this->id;
        }

        return $payload;
    }

    /**
     * بناء طلب من مصفوفة مع تحقق بروتوكولي (jsonrpc = "2.0" و method نصي).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws McpException عند مخالفة بروتوكول JSON-RPC.
     */
    public static function fromArray(array $data): self
    {
        if (($data['jsonrpc'] ?? null) !== self::JSONRPC_VERSION) {
            throw new McpException(McpError::INVALID_REQUEST, 'invalid_request', ['reason' => 'jsonrpc_version']);
        }

        if (! isset($data['method']) || ! is_string($data['method']) || $data['method'] === '') {
            throw new McpException(McpError::INVALID_REQUEST, 'invalid_request', ['reason' => 'method']);
        }

        return new self(
            method: $data['method'],
            params: (array) ($data['params'] ?? []),
            id: isset($data['id']) ? self::normalizeId($data['id']) : null,
        );
    }

    private static function normalizeId(mixed $id): int|string
    {
        if (is_int($id) || is_string($id)) {
            return $id;
        }

        throw new McpException(McpError::INVALID_REQUEST, 'invalid_request', ['reason' => 'id']);
    }
}
