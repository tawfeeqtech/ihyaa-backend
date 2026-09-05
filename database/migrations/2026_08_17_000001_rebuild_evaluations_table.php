<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * إعادة بناء جدول التقييمات إلى مخطط Sprint 2 — data-model.md §2.1 (SRS-DB-05).
     *
     * الاستراتيجية (قرار موثق — "cleanest strategy; dev MVP, no production data"):
     * بدلاً من Schema::rename('ai_evaluations') + ALTER، حُذف جدول النموذج القديم
     * `ai_evaluations` وأُنشئ `evaluations` من الصفر. السبب:
     *  - لا توجد بيانات إنتاج — الحذف+الإنشاء يعطي جدولاً مطابقاً تماماً للمخطط.
     *  - الجدول القديم متباعد بشدة: 6 أعمدة تُحذف (scores/gap_analysis/...) + 7 تُضاف
     *    (result/error_log/consensus_rounds/retry_count/started_at/completed_at/
     *    model_name/provider_used) + تغيير ENUM status ليضم pending + تغيير دقة
     *    decimals (5,2)→(5,1) + إعادة بناء كل الفهارس.
     *  - Schema::rename كانت ستُبقي أسماء فهارس/قيود عتيقة (ai_evaluations_*) واسم FK
     *    غير مطابق للمخطط (evaluations_project_id_foreign عوضاً عن fk_eval_project).
     * الحذف+الإنشاء ينتج المخطط §2.1 حرفياً بما فيه فهرسا DESC واسم FK المطلوب.
     */
    public function up(): void
    {
        Schema::dropIfExists('ai_evaluations');

        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');

            $table->unsignedInteger('version')->default(1);          // متزايد لكل تقييم (SRS §5.4.4)
            $table->enum('status', ['pending', 'processing', 'completed', 'partial', 'failed'])
                ->default('pending');

            $table->decimal('overall_score', 5, 1)->nullable();      // 0.0–100.0 (NULL حتى الاكتمال)
            $table->decimal('confidence_score', 5, 1)->nullable();   // 0.0–100.0
            $table->json('result')->nullable();                     // مخطط §5.4.6.3 كاملاً (أو الجزئي)

            $table->enum('model_used', ['openai', 'claude'])->nullable();  // المزود الذي أجرى فعلياً (FR-206)
            $table->string('model_name', 64)->nullable();           // gpt-4o-mini / claude-3-5-haiku
            $table->string('provider_used', 32)->nullable();        // احتياطي تفصيلي إن اختلف

            $table->unsignedTinyInteger('consensus_rounds')->default(0);  // جولات الإجماع (SRS-AI-O03)
            $table->unsignedTinyInteger('retry_count')->default(0);      // محاولات إعادة يدوية (US-019)
            $table->unsignedInteger('processing_time_ms')->nullable();   // من البدء للاكتمال (SC-001)

            $table->json('error_log')->nullable();                  // [{type, provider, attempt, message, timestamp}]

            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // ——— فهارس §2.1 ———
            $table->index(['project_id', 'status'], 'idx_eval_project_status');
            $table->index(['status', 'completed_at'], 'idx_eval_completed');
            $table->foreign('project_id', 'fk_eval_project')
                ->references('id')->on('projects')->onDelete('cascade');
        });

        // فهارس تنازلية (MySQL 8.0.13+ فقط — SQLite لا تدعم DESC في ALTER TABLE ADD INDEX).
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE evaluations ADD INDEX idx_eval_project_version (project_id, version DESC)');
            DB::statement('ALTER TABLE evaluations ADD INDEX idx_eval_project_created (project_id, created_at DESC)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluations');

        // إعادة جدول النموذج القديم للمعايرة (مطابق حرفياً لـ 2026_08_02_000007_create_ai_evaluations_table).
        Schema::create('ai_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            $table->unsignedInteger('version')->default(1);
            $table->enum('status', ['processing', 'completed', 'failed', 'partial'])
                ->default('processing');

            $table->decimal('overall_score', 5, 2)->nullable();
            $table->json('scores')->nullable();
            $table->json('gap_analysis')->nullable();
            $table->json('recommendations')->nullable();
            $table->json('required_skills')->nullable();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->json('warnings')->nullable();
            $table->enum('model_used', ['openai', 'claude'])->nullable();
            $table->unsignedInteger('processing_time_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'version']);
            $table->index(['project_id', 'status', 'created_at']);
            $table->index('status');
        });
    }
};
