<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * طلبات الاهتمام — SRS-F08 · SRS-DB-07.
     * آلة الحالات: pending → accepted/rejected/cancelled · accepted → cancelled (UC-07 E2).
     *
     * ملاحظة تصميم (انحراف موثق عن docs/database/migrations.md §8):
     * الوثيقة تقترح عموداً مولّداً `active_key` (CASE WHEN status IN ('pending','accepted')
     * THEN investor_id) + فهرس فريد (project_id, active_key) لمنع الطلب النشط المكرر.
     * MySQL 8 يرفض هذا التصميم صراحةً: لا يُسمح لعمود مولّد مخزّن (STORED) أن يشير في
     * تعبيره إلى عمود مشارك في Foreign Key (خطأ 1215 — اختُبر على 8.0.46)، والعمود
     * `investor_id` يحمل FK إلى users. لذلك يُنفَّذ منع التكرار في طبقة التطبيق
     * (InterestController::store → INTEREST_ALREADY_EXISTS — SRS-F08-03).
     */
    public function up(): void
    {
        Schema::create('interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('investor_id')->constrained('users')->cascadeOnDelete();

            $table->enum('interest_type', ['investment', 'technical_development', 'consultation'])
                ->default('investment');
            $table->string('message', 500)->nullable();  // اختياري — حد 500 حرف

            $table->enum('status', ['pending', 'accepted', 'rejected', 'cancelled'])
                ->default('pending');
            $table->string('rejection_reason', 255)->nullable();
            $table->string('agreement_pdf_path', 255)->nullable(); // مستند الاتفاق الثابت — Local Disk
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            // ——— الفهارس ———
            $table->index(['project_id', 'status']);     // لوحة طلبات صاحب الفكرة + الفلاتر
            $table->index(['investor_id', 'status']);    // طلبات المستثمر المرسلة
            $table->index('created_at');                 // الترتيب الزمني
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interests');
    }
};
