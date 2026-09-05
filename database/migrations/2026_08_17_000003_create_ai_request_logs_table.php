<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * سجل طلبات مزودي AI للمعايرة — data-model.md §4 (FR-207 / SRS-AI-M04).
     * قيد صارم: لا حقول نصية لمحتوى الطلب/الاستجابة (لا Prompts/وصف/أسماء مستخدمين)
     * — معرفات ومقاييس فقط (FR-207 / SRS-TEST-AI-11).
     */
    public function up(): void
    {
        Schema::create('ai_request_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evaluation_id')->nullable();  // NULL لطلبات غير التقييم (وكيل التحليل)
            $table->unsignedBigInteger('project_id')->nullable();

            $table->string('dimension', 32)->nullable();   // technical_quality | innovation | ... | consensus | analysis
            $table->enum('provider', ['openai', 'claude']);
            $table->string('model', 64);
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->boolean('success');
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->string('failure_reason', 255)->nullable();   // timeout | 5xx | network | invalid_json | out_of_range | rate_limited
            $table->string('fallback_reason', 255)->nullable();  // سبب التحويل للمزود البديل
            $table->boolean('consensus_round')->default(false);

            $table->timestamp('created_at')->useCurrent();

            // ——— فهارس §4 ———
            $table->index('created_at', 'idx_log_created');
            $table->index(['provider', 'created_at'], 'idx_log_provider');
            $table->index('evaluation_id', 'idx_log_evaluation');
            $table->index(['success', 'created_at'], 'idx_log_success');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_request_logs');
    }
};
