<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EPIC-15 — إضافة حالة المعالجة والخطأ واللغة إلى مخرجات وكيل AI (T103/T104/T122).
 *
 * status:        processing | completed | failed — المسار غير المتزامن (Job) يحتاجه للعرض
 *                ولتمييز "لا يزال قيد المعالجة" (409) عن النتيجة المكتملة.
 * error_message: نص الخطأ عند الفشل (عرض في الواجهة — T117 حالة failed).
 * language:      ar|en — لغة المخرجات (التصدير PDF RTL/LTR — T118).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_agent_artifacts', function (Blueprint $table) {
            $table->enum('status', ['processing', 'completed', 'failed'])
                ->default('processing')
                ->after('analysis_type');
            $table->string('language', 2)->default('ar')->after('status');
            $table->text('error_message')->nullable()->after('model_used');
        });
    }

    public function down(): void
    {
        Schema::table('ai_agent_artifacts', function (Blueprint $table) {
            $table->dropColumn(['status', 'language', 'error_message']);
        });
    }
};
