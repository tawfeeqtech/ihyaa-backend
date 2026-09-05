<?php

/**
 * إعدادات الذكاء الاصطناعي — منصة إحياء (Ihyaa)
 * المرجع: docs/api/enums.md §2.4 (SRS-5.4.6) · SRS-F03
 */

return [

    /*
    |--------------------------------------------------------------------------
    | وضع المحاكاة (Mock)
    |--------------------------------------------------------------------------
    | true في التطوير بدون مفاتيح API — يعيد درجات حتمية لتشغيل خط التقييم كاملاً.
    | لا تُفعَّل أبداً في الإنتاج (AI_MOCK=false).
    */
    'mock' => env('AI_MOCK', false),

    /*
    |--------------------------------------------------------------------------
    | مزودو النماذج — OpenAI الأساسي ثم Claude الاحتياطي (Fallback SRS-F03-03)
    |--------------------------------------------------------------------------
    */
    'openai' => [
        'base_url' => env('AI_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'api_key' => env('AI_OPENAI_API_KEY'),
        'model' => env('AI_OPENAI_MODEL', 'gpt-4o-mini'),
        'timeout' => 45, // ثوانٍ (SRS-AI-P03 — مهلة كل Sub-Agent)
    ],

    'claude' => [
        'base_url' => env('AI_CLAUDE_BASE_URL', 'https://api.anthropic.com/v1'),
        'api_key' => env('AI_CLAUDE_API_KEY'),
        'model' => env('AI_CLAUDE_MODEL', 'claude-3-5-haiku-latest'),
        'timeout' => 45, // ثوانٍ (SRS-AI-P03 — مهلة كل Sub-Agent)
    ],

    /*
    |--------------------------------------------------------------------------
    | المزود الأساسي (SRS-AI-F01/F02)
    |--------------------------------------------------------------------------
    | openai = أساسي · claude = احتياطي. يُدار التحويل (Fallback) داخل FallbackManager
    | مع Circuit Breaker عبر Redis (المفتاح: ai:primary_provider).
    */
    'primary_provider' => env('AI_PRIMARY_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | أبعاد التقييم الخمسة وأوزانها (SRS-5.4.6)
    |--------------------------------------------------------------------------
    */
    'weights' => [
        'technical_quality' => 0.25,
        'innovation' => 0.25,
        'market_viability' => 0.20,
        'team_completeness' => 0.15,
        'documentation' => 0.15,
    ],

    /*
    |--------------------------------------------------------------------------
    | أوزان المعايير الفرعية لكل بُعد (data-model.md §2.3 — SRS-5.4.6.4)
    |--------------------------------------------------------------------------
    | مجموع أوزان معايير كل بُعد = 1.0 — يُختبر آلياً (ScoreCalculator::assertWeightsIntegrity).
    */
    'sub_weights' => [
        'technical_quality' => [
            'code_structure' => 0.40,
            'architecture' => 0.30,
            'testing' => 0.15,
            'ci_cd' => 0.10,
            'documentation' => 0.05,
        ],
        'innovation' => [
            'novelty' => 0.40,
            'problem_originality' => 0.30,
            'approach_creativity' => 0.30,
        ],
        'market_viability' => [
            'problem_clarity' => 0.25,
            'market_size_estimation' => 0.25,
            'business_model_potential' => 0.25,
            'competitive_awareness' => 0.25,
        ],
        'team_completeness' => [
            'skill_diversity' => 0.35,
            'relevant_experience' => 0.35,
            'role_clarity' => 0.30,
        ],
        'documentation' => [
            'project_description' => 0.35,
            'objectives_clarity' => 0.25,
            'supporting_docs_quality' => 0.25,
            'roadmap_clarity' => 0.15,
        ],
    ],

    'dimensions' => [
        'technical_quality' => 'جودة الحل التقني',
        'innovation' => 'الإبداع والتميز',
        'market_viability' => 'الجدوى السوقية',
        'team_completeness' => 'اكتمال الفريق',
        'documentation' => 'التوثيق والبيانات',
    ],

    /*
    |--------------------------------------------------------------------------
    | الحدود الزمنية
    |--------------------------------------------------------------------------
    */
    'per_dimension_timeout' => 45,          // مهلة كل Sub-Agent بالثواني
    'ceiling_seconds' => 180,               // سقف المعالجة المطلق
    'p95_target_ms' => 120_000,             // هدف P95
    'min_dimensions_for_partial' => 3,      // نتيجة جزئية عند اكتمال 3 من 5 أبعاد
    're_evaluation_cache_hours' => 24,      // كاش إعادة التقييم (SRS-AI-C01)
    'max_concurrent_per_user' => 3,         // الحد الأقصى للتقييمات المتزامنة لكل مستخدم
    'artifact_cache_hours' => 24,           // عمر تحليلات وكيل AI المخزنة
    'consensus_threshold' => 20,            // انحراف بُعد عن متوسط البقية بالنقاط يُفعّل جولة الإجماع (SRS-AI-O03)
    'partial_retry_hours' => 1,             // مهلة إعادة محاولة التقييم الجزئي (data-model.md §2.4)

];
