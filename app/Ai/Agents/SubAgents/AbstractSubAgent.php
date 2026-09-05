<?php

namespace App\Ai\Agents\SubAgents;

use App\Ai\Dtos\DimensionResult;
use App\Ai\Mcp\McpError;
use App\Ai\Mcp\McpException;
use App\Ai\Mcp\McpRequest;
use App\Ai\Mcp\McpResponse;
use App\Ai\Prompts\JsonSchema;
use App\Ai\Prompts\PromptsContract;
use App\Ai\Providers\AiProviderContract;
use App\Exceptions\Ai\ProviderException;
use App\Support\ScoreCalculator;
use JsonException;

/**
 * قاعدة Sub-Agent المشتركة (plan.md §1.1 / §1.4).
 *
 * التدفق: يبني الـ Prompt للبُعد ← يستدعي المزوّد ← يحلل JSON ← يعيد DimensionResult.
 * درجة البُعد المعتمدة = المتوسط الموزون للمعايير الفرعية عبر ScoreCalculator
 * (data-model.md §2.3 — اختبار US-015-S2)، ودرجة المُقيِّم المرجعية تُستخدم للتحذير فقط.
 */
abstract class AbstractSubAgent implements SubAgentContract
{
    /**
     * @param  PromptsContract  $prompt  باني الـ Prompt الخاص بالبُعد
     */
    public function __construct(
        protected readonly AiProviderContract $provider,
        protected readonly PromptsContract $prompt,
        protected readonly ScoreCalculator $calculator,
    ) {
    }

    public function evaluate(array $context, string $language = 'ar'): DimensionResult
    {
        $messages = $this->prompt->build($context, $language);

        $options = [
            'temperature' => 0.2,
            'max_tokens' => 2000,
        ];

        if ($this->provider->supportsStructuredOutput()) {
            $options['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => $this->jsonSchema(),
            ];
        } else {
            $options['response_format'] = ['type' => 'json_object'];
        }

        $response = $this->provider->chat($messages, $options);

        return $this->parseResponse($response->content);
    }

    public function handle(McpRequest $request): McpResponse
    {
        $params = $request->params;
        $context = is_array($params['context'] ?? null) ? $params['context'] : [];
        $language = is_string($params['language'] ?? null) ? $params['language'] : 'ar';

        try {
            $result = $this->evaluate($context, $language);

            return McpResponse::success($result->toArray(), $request->id);
        } catch (McpException $e) {
            return McpResponse::error(new McpError($e->rpcCode(), $e->getMessage(), $e->data), $request->id);
        } catch (\Throwable $e) {
            return McpResponse::error(new McpError(McpError::PROVIDER_FAILURE, 'provider_failure', [
                'dimension' => $this->dimension(),
                'reason' => $this->failureReason($e),
            ]), $request->id);
        }
    }

    /**
     * إعادة بناء DimensionResult من مصفوفة نتيجة MCP (للاستهلاك في Orchestrator/ConsensusAgent).
     *
     * @param  array<string, mixed>  $data  صيغة DimensionResult::toArray()
     */
    public static function dimensionResultFromArray(string $dimension, array $data): DimensionResult
    {
        $calculator = new ScoreCalculator();

        $subScores = [];
        foreach ((array) ($data['sub_scores'] ?? []) as $criterion => $score) {
            $subScores[(string) $criterion] = (float) $score;
        }

        $score = $calculator->calculateDimension($dimension, $subScores);

        $strengths = array_values(array_filter(array_map('strval', (array) ($data['strengths'] ?? []))));
        $weaknesses = array_values(array_filter(array_map('strval', (array) ($data['weaknesses'] ?? []))));
        $warnings = array_values(array_filter(array_map('strval', (array) ($data['warnings'] ?? []))));

        return new DimensionResult(
            dimension: $dimension,
            score: $score,
            subScores: $subScores,
            strengths: $strengths,
            weaknesses: $weaknesses,
            confidence: isset($data['confidence']) ? (float) $data['confidence'] : null,
            warnings: $warnings,
        );
    }

    /**
     * مخطط JSON المطلوب للمخرجات (يُمرَّر للمزوّد إن دعم الإخراج المنظّم).
     *
     * @return array<string, mixed>
     */
    protected function jsonSchema(): array
    {
        return JsonSchema::for($this->dimension());
    }

    /**
     * تحليل محتوى الاستجابة النصي إلى DimensionResult.
     *
     * @throws ProviderException عند JSON غير صالح أو نقص معايير فرعية
     *                           (SRS-AI-F01 — المخرجات غير الصالحة تُعامل كفشل مزود).
     */
    protected function parseResponse(string $content): DimensionResult
    {
        $decoded = $this->decodeJson($content);

        if (! is_array($decoded) || ! isset($decoded['sub_scores']) || ! is_array($decoded['sub_scores'])) {
            throw new ProviderException($this->provider->name(), 'Missing sub_scores in response', 1, 'invalid_json');
        }

        $subScores = [];
        foreach ($decoded['sub_scores'] as $criterion => $score) {
            $subScores[(string) $criterion] = (float) $score;
        }

        try {
            $score = $this->calculator->calculateDimension($this->dimension(), $subScores);
        } catch (\InvalidArgumentException $e) {
            throw new ProviderException($this->provider->name(), $e->getMessage(), 1, 'invalid_response');
        }

        $warnings = array_values(array_filter(array_map('strval', (array) ($decoded['warnings'] ?? []))));

        $aiScore = isset($decoded['score']) ? (float) $decoded['score'] : null;
        if ($aiScore !== null && abs($aiScore - $score) > 10.0) {
            $warnings[] = sprintf(
                'انحراف درجة المُقيِّم المرجعية (%s) عن المتوسط الموزون المحسوب (%s)',
                number_format($aiScore, 1),
                number_format($score, 1)
            );
        }

        return new DimensionResult(
            dimension: $this->dimension(),
            score: $score,
            subScores: $subScores,
            strengths: array_values(array_filter(array_map('strval', (array) ($decoded['strengths'] ?? [])))),
            weaknesses: array_values(array_filter(array_map('strval', (array) ($decoded['weaknesses'] ?? [])))),
            confidence: isset($decoded['confidence']) ? (float) $decoded['confidence'] : null,
            warnings: $warnings,
        );
    }

    /**
     * استخراج JSON من نص استجابة متساهلاً مع أطر Markdown (```json ... ```).
     *
     * @return array<string, mixed>|list<mixed>
     *
     * @throws ProviderException
     */
    protected function decodeJson(string $content): array
    {
        $trimmed = trim($content);

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $trimmed, $m) === 1) {
            $trimmed = trim($m[1]);
        }

        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');

        if ($start === false || $end === false || $end <= $start) {
            throw new ProviderException($this->provider->name(), 'No JSON object found in response', 1, 'invalid_json');
        }

        $json = substr($trimmed, $start, $end - $start + 1);

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ProviderException($this->provider->name(), 'Malformed JSON from provider', 1, 'invalid_json', previous: $e);
        }

        if (! is_array($decoded)) {
            throw new ProviderException($this->provider->name(), 'Decoded JSON is not an object', 1, 'invalid_json');
        }

        return $decoded;
    }

    /**
     * تصنيف السبب لسجل MCP (بدون محتوى المشروع — المبدأ V).
     */
    protected function failureReason(\Throwable $e): string
    {
        if ($e instanceof ProviderException) {
            return $e->reason() ?? 'provider_error';
        }

        if ($e instanceof JsonException) {
            return 'invalid_json';
        }

        return 'internal_error';
    }
}
