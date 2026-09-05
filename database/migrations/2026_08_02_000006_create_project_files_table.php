<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ملفات المشروع — SRS-F02-02.
     * الحدود (5 صور × 5MB + 3 PDF × 10MB) تُفرض بالتحقق على مستوى الطلب.
     */
    public function up(): void
    {
        Schema::create('project_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            $table->enum('type', ['image', 'pdf', 'document'])->default('image');
            $table->string('file_path', 255);            // مسار داخل storage/app/public
            $table->string('original_name', 255);        // الاسم الأصلي للعرض فقط
            $table->string('mime_type', 100);            // MIME حقيقي (SRS-NFR-08)
            $table->unsignedBigInteger('file_size');     // بالبايت
            $table->boolean('is_cover')->default(false); // صورة الغلاف في بطاقة المعرض
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_files');
    }
};
