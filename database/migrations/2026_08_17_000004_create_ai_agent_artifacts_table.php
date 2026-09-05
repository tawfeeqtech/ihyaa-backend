<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * مخرجات وكيل تحليل المشروع — data-model.md §5 (SRS-DB-09).
     * artifact_data: نصوص وقوالب فقط (SRS-AI-M03) — SWOT/مقارنة/سوق/تنافسي.
     */
    public function up(): void
    {
        Schema::create('ai_agent_artifacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');

            $table->enum('analysis_type', ['competitive', 'swot', 'market', 'comparison']);
            $table->json('artifact_data');
            $table->unsignedInteger('version')->default(1);
            $table->enum('model_used', ['openai', 'claude'])->nullable();

            $table->timestamp('created_at')->useCurrent();

            // ——— قيود وفهارس §5 ———
            $table->unique(['project_id', 'analysis_type', 'version'], 'uq_artifact_type_version');
            $table->foreign('project_id', 'fk_artifact_project')
                ->references('id')->on('projects')->onDelete('cascade');
        });

        // فهرس تنازلي (MySQL 8.0.13+ فقط — SQLite لا تدعم DESC).
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE ai_agent_artifacts ADD INDEX idx_artifact_project (project_id, created_at DESC)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_artifacts');
    }
};
