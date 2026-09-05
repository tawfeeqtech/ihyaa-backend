<?php

namespace App\Ai\Providers;

/**
 * عقد مزوّد AI (plan.md §3.1 — SRS-AI-F01/F02).
 *
 * تطبّقه OpenAiProvider (openai-php/client) و ClaudeProvider (HTTP خام عبر Illuminate\Http\Client).
 * كلاهما قابل للضبط عبر `.env`: AI_OPENAI_MODEL / AI_CLAUDE_MODEL / AI_PRIMARY_PROVIDER.
 */
interface AiProviderContract
{
    /**
     * اسم المزوّد: 'openai' | 'claude' (يُخزَّن في evaluations.model_used / ai_request_logs.provider).
     */
    public function name(): string;

    /**
     * استدعاء محادثة.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options  model, temperature, max_tokens, response_format, json_schema ...
     *
     * @throws \App\Exceptions\Ai\ProviderException عند فشل المزوّد (شبكة/مهلة/5xx/JSON).
     */
    public function chat(array $messages, array $options): AiResponse;

    /**
     * هل يدعم المزوّد الإخراج المنظّم (JSON Schema)؟
     * OpenAI: response_format=json_schema · Claude: غير مدعوم في قاعدة MVP (HTTP خام).
     */
    public function supportsStructuredOutput(): bool;
}
