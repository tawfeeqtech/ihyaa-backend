<?php

namespace App\Services;

use App\Enums\ModelUsed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * بوابة الذكاء الاصطناعي — SRS-F03.
 * OpenAI أساسي ← Claude احتياطي (Fallback مع تسجيل السبب — SRS-F03-03).
 * وضع Mock (AI_MOCK=true) يعيد درجات حتمية في التطوير دون مفاتيح API.
 */
class AiGateway
{
    /**
     * تقييم بُعد واحد (Sub-Agent) — يركّز كل Job على بُعد واحد.
     *
     * @return array{score: float, sub_scores: array<string, float>, analysis: string,
     *               gaps: string[], recommendations: string[], skills: string[],
     *               confidence: float, model_used: string}
     */
    public function evaluateDimension(string $dimension, array $projectData): array
    {
        if (config('ai.mock')) {
            return $this->mockDimension($dimension, $projectData);
        }

        $prompt = $this->buildDimensionPrompt($dimension, $projectData);
        $response = $this->chat($prompt);

        return $this->normalizeDimensionResult($dimension, $response);
    }

    /**
     * تحليل مشروع عبر وكيل AI (competitive | swot | market | comparison) — SRS-API-42.
     *
     * @return array{type: string, content: string, summary: string, model_used: string}
     */
    public function analyzeProject(string $type, array $projectData): array
    {
        if (config('ai.mock')) {
            return $this->mockAnalysis($type, $projectData);
        }

        $prompt = $this->buildAnalysisPrompt($type, $projectData);
        $response = $this->chat($prompt, jsonMode: false);

        $content = is_string($response) ? $response : json_encode($response, JSON_UNESCAPED_UNICODE);

        return [
            'type' => $type,
            'content' => $content,
            'summary' => Str::limit(strip_tags($content), 300),
            'model_used' => $response['_model_used'] ?? 'openai',
        ];
    }

    /**
     * تحليل منظم (JSON) عبر مزودي النماذج الحاليين — EPIC-15 (T107/T111).
     *
     * يُعيد استجابة JSON مُحلَّلة من OpenAI (أساسي) ← Claude (احتياطي) عبر chat()
     * مع مفتاح _model_used. في وضع Mock يُعيد بنية حتمية صالحة للمخطط لكل نوع.
     *
     * @return array<string, mixed>
     */
    public function analyzeStructured(string $type, string $prompt): array
    {
        if (config('ai.mock')) {
            return $this->mockStructured($type);
        }

        $response = $this->chat($prompt, jsonMode: true);

        if (is_string($response)) {
            throw new RuntimeException('استجابة النموذج ليست JSON صالحاً');
        }

        return $response;
    }

    // ——————————————————————— مزودو النماذج ———————————————————————

    /**
     * محادثة مع النموذج: OpenAI أولاً، ثم Claude عند الفشل.
     * يُعيد JSON مُحلَّلاً (أو نصاً عند jsonMode=false) مع مفتاح _model_used.
     */
    protected function chat(string $prompt, bool $jsonMode = true): array|string
    {
        $exceptions = [];

        foreach ([ModelUsed::OPENAI, ModelUsed::CLAUDE] as $provider) {
            try {
                $result = $provider === ModelUsed::OPENAI
                    ? $this->callOpenAi($prompt, $jsonMode)
                    : $this->callClaude($prompt, $jsonMode);

                if (is_string($result)) {
                    return ['content' => $result, '_model_used' => $provider->value];
                }

                $result['_model_used'] = $provider->value;

                return $result;
            } catch (\Throwable $e) {
                $exceptions[$provider->value] = $e->getMessage();
                Log::warning('ai.fallback', [
                    'provider' => $provider->value,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        Log::error('ai.all_providers_failed', ['exceptions' => $exceptions]);

        throw new RuntimeException('فشلت جميع مزودات الذكاء الاصطناعي: '.implode(' | ', $exceptions));
    }

    protected function callOpenAi(string $prompt, bool $jsonMode): array|string
    {
        $cfg = config('ai.openai');
        $payload = [
            'model' => $cfg['model'],
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.3,
            'max_tokens' => 2000,
        ];
        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $response = Http::timeout($cfg['timeout'])
            ->withToken($cfg['api_key'])
            ->post($cfg['base_url'].'/chat/completions', $payload);

        $response->throw();

        $content = $response->json('choices.0.message.content');

        return $jsonMode ? $this->decodeJson($content) : $content;
    }

    protected function callClaude(string $prompt, bool $jsonMode): array|string
    {
        $cfg = config('ai.claude');
        $payload = [
            'model' => $cfg['model'],
            'max_tokens' => 2000,
            'temperature' => 0.3,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];

        $response = Http::timeout($cfg['timeout'])
            ->withHeaders([
                'x-api-key' => $cfg['api_key'],
                'anthropic-version' => '2023-06-01',
            ])
            ->post($cfg['base_url'].'/messages', $payload);

        $response->throw();

        $content = $response->json('content.0.text');

        return $jsonMode ? $this->decodeJson($content) : $content;
    }

    // ——————————————————————— التطبيع ———————————————————————

    protected function decodeJson(string $content): array
    {
        // إزالة أطر الكود إن وُجدت (```json ... ```)
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = preg_replace('/\s*```$/', '', $content);
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('استجابة النموذج ليست JSON صالحاً');
        }

        return $decoded;
    }

    protected function normalizeDimensionResult(string $dimension, array $raw): array
    {
        return [
            'score' => round((float) ($raw['score'] ?? $raw['overall_score'] ?? 0), 2),
            'sub_scores' => (array) ($raw['sub_scores'] ?? $raw['criteria'] ?? []),
            'analysis' => (string) ($raw['analysis'] ?? $raw['justification'] ?? ''),
            'gaps' => (array) ($raw['gaps'] ?? []),
            'recommendations' => (array) ($raw['recommendations'] ?? []),
            'skills' => (array) ($raw['required_skills'] ?? $raw['skills'] ?? []),
            'confidence' => round((float) ($raw['confidence'] ?? 0.7), 2),
            'model_used' => $raw['_model_used'] ?? 'openai',
        ];
    }

    // ——————————————————————— بناء البرومبتات ———————————————————————

    protected function projectBrief(array $projectData): string
    {
        return "Project: {$projectData['title']}\n"
            ."Description: {$projectData['description']}\n"
            ."Category: {$projectData['category']}\n"
            .'Tags: '.implode(', ', $projectData['tags'] ?? [])."\n"
            .'Budget: '.($projectData['budget_min'] ?? '-').' - '.($projectData['budget_max'] ?? '-')."\n"
            .'Github: '.($projectData['github_url'] ?? '-')."\n"
            .'Owner background: '.($projectData['owner_university'] ?? '-').' / '.($projectData['owner_major'] ?? '-')."\n"
            .'Team size: '.($projectData['team_size'] ?? 1)."\n";
    }

    protected function buildDimensionPrompt(string $dimension, array $projectData): string
    {
        return 'You are an expert startup evaluator at an investment marketplace. '
            ."Evaluate ONLY the dimension '{$dimension}' of the following project.\n\n"
            .$this->projectBrief($projectData)
            ."\nRespond with strict JSON only, no markdown, using this schema:\n"
            .'{"score": number 0-100, "sub_scores": {"criterion": number 0-100, ...}, '
            .'"analysis": "short justification", "gaps": ["...", "..."], '
            .'"recommendations": ["...", "..."], "required_skills": ["...", "..."], "confidence": number 0-1}';
    }

    protected function buildAnalysisPrompt(string $type, array $projectData): string
    {
        $brief = $this->projectBrief($projectData);

        return match ($type) {
            'competitive' => "You are a market analyst. Produce a competitive report for this project.\n\n{$brief}\n"
                .'Structure: competitive advantages, expected market share, main competitors, differentiation strategy.',
            'swot' => "You are a business strategist. Produce a SWOT analysis (4+ points per category: strengths, weaknesses, opportunities, threats) for this project.\n\n{$brief}",
            'market' => "You are a market researcher. Produce a market report (TAM/SAM/SOM, target segments, pricing, go-to-market) for this project.\n\n{$brief}",
            default => "You are an analyst. Compare this project to 3-5 similar projects in the same category, and give a positioning comparison.\n\n{$brief}",
        };
    }

    // ——————————————————————— وضع المحاكاة (Mock) ———————————————————————

    protected function mockDimension(string $dimension, array $projectData): array
    {
        // درجات حتمية تعتمد على عنوان المشروع (حتى تكون النتائج مستقرة بين المحاولات)
        $seed = crc32($dimension.':'.$projectData['title']);
        $base = [
            'technical_quality' => 74.0,
            'innovation' => 68.0,
            'market_viability' => 65.0,
            'team_completeness' => 70.0,
            'documentation' => 61.0,
        ];
        $jitter = (float) (($seed % 9) - 4); // -4 .. +4

        $score = max(20, min(98, $base[$dimension] + $jitter));

        return [
            'score' => $score,
            'sub_scores' => [
                'clarity' => max(0, $score - 8),
                'completeness' => max(0, $score - 12),
                'feasibility' => min(100, $score + 5),
            ],
            'analysis' => "Mock evaluation for {$dimension} (AI_MOCK=true — config/ai.php).",
            'gaps' => ["Missing details for {$dimension}"],
            'recommendations' => ["Improve documentation of {$dimension}"],
            'skills' => ['Project management', 'Domain expertise'],
            'confidence' => 0.72,
            'model_used' => 'openai',
        ];
    }

    /**
     * بنية منظمة حتمية (Mock) لتحليل SWOT/التنافسي — EPIC-15.
     * صالحة للمخطط (≥4 لكل فئة SWOT · ≥3 توصيات · مفاتيح competitive كاملة).
     */
    protected function mockStructured(string $type): array
    {
        return match ($type) {
            'swot' => [
                'summary' => 'تحليل SWOT شامل معتمد على آخر تقييم رسمي للمشروع.',
                'strengths' => [
                    'قيمة مقترحة واضحة ومحددة',
                    'استخدام الذكاء الاصطناعي في التقييم والتحليل',
                    'استهداف سوق الشرق الأوسط النامي',
                    'فريق يتمتع بتنوع في المهارات التقنية',
                ],
                'weaknesses' => [
                    'قنوات توزيع محدودة في البداية',
                    'الاعتماد على بيانات خارجية لتغذية النموذج',
                    'نقص خبرة تسويقية موثقة',
                    'حاجة إلى تحسين التوثيق والبيانات',
                ],
                'opportunities' => [
                    'نمو سوق رأس المال الجريء في المنطقة',
                    'شراكات مع الجامعات والمسرعات',
                    'التوسع الجغرافي نحو الإمارات ومصر',
                    'إضافة فئة المستقلين بعد الإطلاق',
                ],
                'threats' => [
                    'دخول منصات عالمية كبرى إلى السوق',
                    'تقلبات اقتصادية تؤثر على الاستثمار',
                    'مخاطر دقة التقييم الآلي',
                    'منافسة محلية ناشئة',
                ],
                'recommendations' => [
                    'التركيز على السوق السعودي أولاً',
                    'بناء شراكات استراتيجية مع الجامعات',
                    'تحسين عملية جمع البيانات ومراجعتها',
                ],
                'derived_from' => ['last_evaluation'],
                '_model_used' => 'openai',
            ],
            'competitive' => [
                'competitive_advantage' => [
                    'نظام تقييم AI شفاف ومكوّن من خمسة أبعاد',
                    'تركيز حصري على السوق العربي (RTL كامل)',
                    'سرعة معالجة التحليلات مع تقارير مخصصة',
                ],
                'differentiators' => [
                    'تحليل SWOT ومقارنة تنافسية تلقائية',
                    'دعم كامل للغة العربية',
                    'نصوص/قوالب فقط بلا التزامات خارجية في MVP',
                ],
                'gaps_to_address' => [
                    'غياب قاعدة بيانات واسعة للمشاريع في البداية',
                    'حاجة إلى تحسين التسويق والانتشار',
                ],
                'recommendations' => [
                    'توسيع قاعدة المشاريع الأولية عبر الشراكات',
                    'الاستفادة من تجارب الجامعات والهاكاثونات',
                ],
                '_model_used' => 'openai',
            ],
            default => [
                'summary' => '',
                'note' => "No structured mock for type: {$type}",
                '_model_used' => 'openai',
            ],
        };
    }

    protected function mockAnalysis(string $type, array $projectData): array
    {
        $title = $projectData['title'];
        $content = match ($type) {
            'competitive' => "## Competitive Report — {$title}\n\n"
                ."- **Advantages:** AI-powered evaluation, local market focus\n"
                ."- **Expected share:** 5-8% of the niche segment in year 1\n"
                ."- **Main competitors:** WellFound, F6S\n"
                .'- **Differentiation:** transparent scoring + MENA focus',
            'swot' => "## SWOT — {$title}\n\n"
                ."- **Strengths:** clear value proposition, strong team\n"
                ."- **Weaknesses:** limited distribution channels\n"
                ."- **Opportunities:** growing MENA VC market\n"
                .'- **Threats:** incumbent platforms entering the niche',
            'market' => "## Market Report — {$title}\n\n"
                ."- **TAM:** \$10-15B · **SAM:** \$500M-1.2B · **SOM:** \$20-40M (3 years)\n"
                ."- **Target segments:** KSA → UAE → Egypt\n"
                .'- **Pricing:** freemium + subscriptions',
            default => "## Comparison — {$title}\n\n"
                ."- 3 similar projects found in the same category\n"
                ."- This project ranks above median on innovation and documentation\n"
                .'- Recommended positioning: emphasize the AI evaluation differentiator',
        };

        return [
            'type' => $type,
            'content' => $content,
            'summary' => Str::limit($content, 300),
            'model_used' => 'openai',
        ];
    }
}
