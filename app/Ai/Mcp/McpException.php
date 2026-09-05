<?php

namespace App\Ai\Mcp;

use Exception;
use Throwable;

/**
 * استثناء بروتوكول MCP الداخلي (JSON-RPC 2.0 — plan.md §1.4 / SRS-AI-M01..M04).
 * يحمل كود RPC مستقلاً عن كود PHP Exception ليُعرَض في رد JSON-RPC.
 */
class McpException extends Exception
{
    /**
     * @param  int  $rpcCode  كود JSON-RPC (انظر McpError::*)
     * @param  array<string, mixed>  $data  بيانات سياقية إضافية
     * @param  string  $message  رمز خطأ قصير
     */
    public function __construct(
        public readonly int $rpcCode = McpError::INTERNAL_ERROR,
        string $message = 'internal_error',
        public readonly array $data = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function rpcCode(): int
    {
        return $this->rpcCode;
    }

    /**
     * @return array{code: int, message: string, data: array<string, mixed>}
     */
    public function toErrorPayload(): array
    {
        return (new McpError($this->rpcCode, $this->message, $this->data))->toArray();
    }
}
