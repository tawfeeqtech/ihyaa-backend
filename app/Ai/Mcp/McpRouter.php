<?php

namespace App\Ai\Mcp;

use App\Ai\Agents\SubAgents\SubAgentContract;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * موجّه MCP الداخلي — JSON-RPC 2.0 (plan.md §1.4 — SRS-AI-M01/M02/M04).
 *
 * يوزّع طلبات `agent.{dimension}.evaluate` إلى Sub-Agent المسجّل، ويسجّل كل
 * تفاعل (SRS-AI-M04) في `ai` log بمقاييس فقط (لا محتوى مشروع — المبدأ V):
 * evaluation_id · project_id · dimension · success · latency_ms · reason.
 *
 * لا اتصال خارجي — الوسيط داخل العملية الواحدة (SRS-AI-M01).
 */
class McpRouter
{
    private const METHOD_PATTERN = '/^agent\.([a-z_]+)\.evaluate$/';

    /**
     * @var array<string, SubAgentContract> مفتاح: dimension
     */
    private array $agents = [];

    /**
     * @param  iterable<SubAgentContract>|array<string, SubAgentContract>  $agents
     */
    public function __construct(iterable $agents = [])
    {
        foreach ($agents as $agent) {
            $this->register($agent);
        }
    }

    public function register(SubAgentContract $agent): void
    {
        $this->agents[$agent->dimension()] = $agent;
    }

    public function hasAgent(string $dimension): bool
    {
        return isset($this->agents[$dimension]);
    }

    /**
     * توجيه طلب JSON-RPC 2.0 إلى Sub-Agent المعني وتسجيل التفاعل.
     */
    public function dispatch(McpRequest $request): McpResponse
    {
        $started = hrtime(true);

        $dimension = $this->resolveDimension($request->method);

        if ($dimension === null) {
            $response = McpResponse::error(
                new McpError(McpError::METHOD_NOT_FOUND, 'method_not_found', ['method' => $request->method]),
                $request->id
            );
            $this->logInteraction($request, null, $response, $this->latencyMs($started));

            return $response;
        }

        $agent = $this->agents[$dimension] ?? null;

        if ($agent === null) {
            $response = McpResponse::error(
                new McpError(McpError::METHOD_NOT_FOUND, 'method_not_found', ['dimension' => $dimension]),
                $request->id
            );
            $this->logInteraction($request, $dimension, $response, $this->latencyMs($started));

            return $response;
        }

        $response = $agent->handle($request);
        $this->logInteraction($request, $dimension, $response, $this->latencyMs($started));

        return $response;
    }

    /**
     * @return list<string> الأبعاد المسجّلة
     */
    public function dimensions(): array
    {
        return array_keys($this->agents);
    }

    private function resolveDimension(string $method): ?string
    {
        if (preg_match(self::METHOD_PATTERN, $method, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function latencyMs(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }

    /**
     * تسجيل التفاعل في سجل `ai` — مقاييس ومعرّفات فقط، بلا محتوى المشروع (SRS-AI-M04 / المبدأ V).
     *
     * @param  string|null  $dimension
     */
    private function logInteraction(McpRequest $request, ?string $dimension, McpResponse $response, int $latencyMs): void
    {
        $params = $request->params;
        $error = $response->error;

        $payload = [
            'jsonrpc_id' => $request->id,
            'method' => $request->method,
            'dimension' => $dimension,
            'evaluation_id' => isset($params['evaluation_id']) ? (int) $params['evaluation_id'] : null,
            'project_id' => isset($params['project_id']) ? (int) $params['project_id'] : null,
            'consensus_round' => (bool) ($params['consensus_round'] ?? false),
            'success' => $response->isSuccess(),
            'latency_ms' => $latencyMs,
        ];

        if ($error !== null) {
            $payload['rpc_code'] = $error->code;
            $payload['error'] = $error->message;
            $payload['error_data'] = $error->data;
        }

        if ($response->isSuccess()) {
            $this->logger()->info('ai.mcp_interaction', $payload);
        } else {
            $this->logger()->warning('ai.mcp_interaction.failed', $payload);
        }
    }

    /**
     * قناة `ai` إن كانت مكوّنة، وإلا القناة الافتراضية (لا تُعدَّل config خارج النطاق).
     */
    private function logger(): LoggerInterface
    {
        $channel = config('logging.channels.ai');

        return $channel !== null
            ? Log::channel('ai')
            : Log::channel((string) config('logging.default', 'stack'));
    }

    /**
     * بناء موجّه من مصفوفة Sub-Agents (مساعدة للمصنع).
     *
     * @param  iterable<SubAgentContract>  $agents
     *
     * @throws InvalidArgumentException إذا لم يحقّق عنصر واجهة SubAgentContract
     */
    public static function fromAgents(iterable $agents): self
    {
        $router = new self();

        foreach ($agents as $agent) {
            if (! $agent instanceof SubAgentContract) {
                throw new InvalidArgumentException('McpRouter expects SubAgentContract instances.');
            }
            $router->register($agent);
        }

        return $router;
    }
}
