<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تقييمات AI — SRS-F03 · SRS-DB-04/05.
     * سجل كامل — لا حذف تلقائي؛ آخر 5 مكتملة فقط تُعرض للمقارنة.
     */
    public function up(): void
    {
        Schema::create('ai_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            // يتزايد تلقائياً لكل محاولة تقييم (بما فيها إعادة المحاولة بعد الفشل)
            $table->unsignedInteger('version')->default(1);
            $table->enum('status', ['processing', 'completed', 'failed', 'partial'])
                ->default('processing');

            $table->decimal('overall_score', 5, 2)->nullable();   // 0.00–100.00
            $table->json('scores')->nullable();          // 5 أبعاد + معايير فرعية (مخطط SRS §5.4.6.3)
            $table->json('gap_analysis')->nullable();    // technical | market | team | documentation
            $table->json('recommendations')->nullable(); // immediate | short_term | long_term
            $table->json('required_skills')->nullable(); // مهارات مطلوبة
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->json('warnings')->nullable();        // تحذيرات (نقص بيانات…)
            $table->enum('model_used', ['openai', 'claude'])->nullable(); // Fallback (SRS-F03-03)
            $table->unsignedInteger('processing_time_ms')->nullable();   // هدف P95: 120s — سقف 180s
            $table->text('error_message')->nullable();   // سجل التدقيق (SRS-F03-05)
            $table->timestamps();

            // سجل كامل — لا حذف تلقائي (SRS-DB-05)
            $table->unique(['project_id', 'version']);
            $table->index(['project_id', 'status', 'created_at']); // آخر 5 مكتملة للمقارنة
            $table->index('status');                             // عدادات لوحة المشرف
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_evaluations');
    }
};
