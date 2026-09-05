<?php

namespace App\Ai\Agents\SubAgents;

use App\Ai\Dtos\DimensionResult;
use App\Ai\Mcp\McpRequest;
use App\Ai\Mcp\McpResponse;

/**
 * عقد Sub-Agent — يقيّم بُعداً واحداً بمعاييره الفرعية (plan.md §1.1 / §8).
 *
 * يُستدعى عبر McpRouter (JSON-RPC داخلي): كل Sub-Agent هو نقطة نهاية MCP
 * (method: agent.{dimension}.evaluate). يبني سياقاً مخصصاً فقط (SRS-AI-O02)
 * ويعيد نتيجة بُعد واحدة.
 *
 * المتطلبات: تعقيم المدخلات · حارس حقن الأوامر · لا محتوى مشروع في السجلات (المبدأ V).
 */
interface SubAgentContract
{
    /**
     * مفتاح البُعد: technical_quality | innovation | market_viability | team_completeness | documentation.
     */
    public function dimension(): string;

    /**
     * تقييم البُعد من سياق مخصص (SRS-AI-O02).
     *
     * @param  array<string, mixed>  $context  سياق البُعد فقط (وصف/README/فريق/سوق ...)
     * @param  string  $language  'ar' | 'en' — لغة محتوى المشروع (التقييم بلا مكافئ > 5%)
     */
    public function evaluate(array $context, string $language = 'ar'): DimensionResult;

    /**
     * معالجة طلب MCP مباشرة — يوزّع McpRouter الطلبات هنا (plan.md §1.4).
     */
    public function handle(McpRequest $request): McpResponse;
}
