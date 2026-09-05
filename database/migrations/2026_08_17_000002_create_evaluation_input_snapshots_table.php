<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * لقطة مدخلات التقييم — data-model.md §3 (Edge Case السبرنت).
     * قيد الخصوصية (المبدأ V / SRS-TEST-AI-11): بلا بيانات حساسة — لا بريد/هاتف/
     * روابط ملفات داخلية؛ محتوى الملفات (PDF/صور) لا يُنسخ — وصف وصفى فقط.
     */
    public function up(): void
    {
        Schema::create('evaluation_input_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evaluation_id');
            $table->unsignedBigInteger('project_id');

            $table->text('description')->nullable();        // نص الوصف (≤ 2000 حرف) — حق التدقيق
            $table->string('github_url', 255)->nullable();  // الرابط (للتحكيم اللاحق)
            $table->json('files_meta')->nullable();         // [{type, size, mime, original_name}]
            $table->json('video_meta')->nullable();         // {provider, url}
            $table->json('team_meta')->nullable();          // [{name, role, skills}] — بلا PII خارجي
            $table->text('business_info')->nullable();      // معلومات العمل (≤ 1000 حرف)

            $table->timestamp('created_at')->useCurrent();

            // ——— قيود وفهارس §3 ———
            $table->unique('evaluation_id');
            $table->index('project_id', 'idx_snap_project');
            $table->foreign('evaluation_id', 'fk_snap_eval')
                ->references('id')->on('evaluations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_input_snapshots');
    }
};
