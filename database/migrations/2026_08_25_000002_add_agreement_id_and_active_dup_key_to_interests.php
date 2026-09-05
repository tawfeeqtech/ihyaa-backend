<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * T041 · T056 — توسيع interests لإتفاقات EPIC-08:
     *
     * 1) الحالة الجديدة `accepted_pending_document` (فشل PDF مؤقت — FR-310).
     * 2) عمود `agreement_id` — ربط 1:1 مع جدول agreements (عند نجاح توليد PDF).
     * 3) عمود مولّد `active_dup_key` + فهرس فريد (project_id, active_dup_key):
     *    منع الطلب النشط المكرر على مستوى قاعدة البيانات (سباق التزامن — T043).
     *
     * ملاحظة انحراف موثقة عن docs/database/migrations.md §8: الوثيقة القديمة
     * افترضت خطأ 1215 من MySQL لعمود مولّد STORED يشير إلى عمود FK. استخدمنا
     * VIRTUAL (لا يُكتب على القرص) — اختُبر تجريبياً على MySQL 8.4 و SQLite
     * 3.40: كلاهما يقبل VIRTUAL + فهرس فريد مركّب عليه. عند الحالة غير النشطة
     * يُقيَّم العمود إلى NULL، والفهارس الفريدة في كلا المحركين تسمح بتكرار
     * NULL — فيبقى مسموحاً بأي عدد من الطلبات المرفوضة/الملغاة لنفس المشروع.
     */
    public function up(): void
    {
        Schema::table('interests', function (Blueprint $table) {
            // 1) حالة وسيطة جديدة في تعداد الحالة.
            $table->enum('status', [
                'pending',
                'accepted',
                'accepted_pending_document',
                'rejected',
                'cancelled',
            ])->default('pending')->change();

            // 2) ربط الاتفاق الناجح (nullable حتى يُنشأ PDF).
            $table->foreignId('agreement_id')
                ->nullable()
                ->after('agreement_pdf_path')
                ->constrained('agreements')
                ->nullOnDelete();

            // 3) العمود المولّد: investor_id فقط للحالات النشطة، NULL لغيرها.
            $table->unsignedBigInteger('active_dup_key')
                ->nullable()
                ->after('investor_id')
                ->virtualAs("CASE WHEN status IN ('pending','accepted','accepted_pending_document') THEN investor_id END");

            $table->unique(['project_id', 'active_dup_key'], 'interests_active_dup_unique');
        });
    }

    public function down(): void
    {
        Schema::table('interests', function (Blueprint $table) {
            $table->dropUnique('interests_active_dup_unique');
            $table->dropColumn(['active_dup_key']);
            $table->dropConstrainedForeignId('agreement_id');

            // استرجاع تعداد الحالة الأصلي.
            $table->enum('status', ['pending', 'accepted', 'rejected', 'cancelled'])
                ->default('pending')
                ->change();
        });
    }
};
