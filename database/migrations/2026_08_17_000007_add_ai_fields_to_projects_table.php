<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * إضافات جدول المشاريع — data-model.md §7 (SRS-DB-03).
     * ai_score و last_evaluation_at موجودان مسبقاً من 2026_08_02_000005؛ هنا يُضاف
     * كاش العرض ai_evaluation (أحدث result لمستوى L1) + فهرسا الفرز المطلوبان.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('ai_evaluation')->nullable()->after('ai_score');  // كاش عرض: أحدث result
        });

        // فهرس تنازلي على ai_score + فهرس on last_evaluation_at (MySQL 8.0.13+ فقط).
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE projects ADD INDEX idx_projects_ai_score (ai_score DESC)');
            DB::statement('ALTER TABLE projects ADD INDEX idx_projects_last_eval (last_evaluation_at)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE projects DROP INDEX idx_projects_ai_score');
            DB::statement('ALTER TABLE projects DROP INDEX idx_projects_last_eval');
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('ai_evaluation');
        });
    }
};
