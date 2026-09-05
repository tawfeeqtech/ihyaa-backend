<?php

namespace App\Ai\Agents;

use App\Ai\Agents\SubAgents\AbstractSubAgent;
use App\Ai\Dtos\DimensionResult;
use App\Ai\Mcp\McpRequest;
use App\Ai\Mcp\McpRouter;

/**
 * وكيل الإجماع (plan.md §1.5 — SRS-AI-O03).
 *
 * عند انحراف بُعد عن متوسط بقية الأبعاد بأكثر من العتبة (`config('ai.consensus_threshold')`)،
 * يعيد تقييم البُعد المنحرف في ضوء الأبعاد الأربعة الأخرى — جولة واحدة كحد أقصى
 * (حارس التكلفة والزمن). النتيجة المعتمدة = نتيجة الجولة إن اجتازت البناء، وإلا تبقى الأصلية.
 */
class ConsensusAgent
{
    public const MAX_ROUNDS = 1;

    private const CONSENSUS_ID_OFFSET = 1000;

    public function __construct(
        private readonly McpRouter $router,
    ) {
    }

    /**
     * تنفيذ جولة إجماع واحدة لبُعد منحرف.
     *
     * @param  array<string, DimensionResult>  $otherDimensions  الأبعاد الأربعة الأخرى (مفتاح: dimension)
     * @param  array<string, mixed>  $baseContext  سياق البُعد الأصلي (SRS-AI-O02)
     *
     * @return DimensionResult|null النتيجة المنقّحة، أو null لِتبقى النتيجة الأصلية
     */
    public function run(
        DimensionResult $deviant,
        array $otherDimensions,
        array $baseContext,
        string $language = 'ar',
        ?int $evaluationId = null,
        ?int $projectId = null,
    ): ?DimensionResult {
        $context = $baseContext;
        $context['consensus_review'] = $this->buildReviewPayload($deviant, $otherDimensions);

        $request = new McpRequest(
            method: "agent.{$deviant->dimension}.evaluate",
            params: [
                'evaluation_id' => $evaluationId,
                'project_id' => $projectId,
                'language' => $language,
                'context' => $context,
                'schema_version' => '1.0',
                'consensus_round' => true,
            ],
            id: $this->requestId($deviant->dimension, $evaluationId),
        );

        $response = $this->router->dispatch($request);

        if (! $response->isSuccess() || ! is_array($response->result)) {
            return null; // فشل الجولة — تبقى النتيجة الأصلية
        }

        try {
            return AbstractSubAgent::dimensionResultFromArray($deviant->dimension, $response->result);
        } catch (\Throwable) {
            return null; // نتيجة الجولة غير صالحة — تبقى الأصلية
        }
    }

    /**
     * @param  array<string, DimensionResult>  $otherDimensions
     * @return array<string, mixed>
     */
    private function buildReviewPayload(DimensionResult $deviant, array $otherDimensions): array
    {
        $others = [];
        foreach ($otherDimensions as $dimension => $result) {
            $others[] = [
                'dimension' => $result->dimension ?: $dimension,
                'score' => $result->score,
                'strengths' => $result->strengths,
                'weaknesses' => $result->weaknesses,
            ];
        }

        return [
            'deviant_dimension' => $deviant->dimension,
            'deviant_score' => $deviant->score,
            'deviant_sub_scores' => $deviant->subScores,
            'other_dimensions' => $others,
        ];
    }

    private function requestId(string $dimension, ?int $evaluationId): ?int
    {
        if ($evaluationId === null) {
            return null;
        }

        return $evaluationId * 10 + self::CONSENSUS_ID_OFFSET + $this->dimensionIndex($dimension);
    }

    private function dimensionIndex(string $dimension): int
    {
        $index = array_search($dimension, array_keys((array) config('ai.weights', [])), true);

        return $index === false ? 0 : (int) $index;
    }
}
