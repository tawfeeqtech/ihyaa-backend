<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * مستندات الاتفاق الثابتة — SRS-DB-07 · data-model.md §2.2 (T056 backend).
     *
     * علاقة 1:1 مع interests عبر interest_id الفريد. الأسماء (idea_owner_name /
     * investor_name) نسخة وقت القبول (snapshot) — مستند PDF الثابت لا يتغير نصّه
     * إذا عدّل المستخدم اسمه لاحقاً (research.md).
     */
    public function up(): void
    {
        Schema::create('agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interest_id')->unique()->constrained()->cascadeOnDelete(); // 1:1
            $table->foreignId('idea_owner_id')->constrained('users');
            $table->foreignId('investor_id')->constrained('users');
            $table->foreignId('project_id')->constrained();

            // مسار Local Disk: agreements/agreement-{interest_id}-{investor_id}-{Y-m-d}.pdf
            $table->string('pdf_path');
            $table->string('idea_owner_name');   // اسم الطرف الأول (صاحب الفكرة) — سريع دون JOIN
            $table->string('investor_name');     // اسم الطرف الثاني — سريع دون JOIN

            $table->timestamps();                // created_at = تاريخ الاتفاق (يُطبع في PDF)

            // ——— الفهارس ———
            $table->index('idea_owner_id');
            $table->index('investor_id');
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreements');
    }
};
