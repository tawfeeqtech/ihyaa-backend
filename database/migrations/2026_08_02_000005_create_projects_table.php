<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * المشاريع — SRS-F02 · SRS-DB-03.
     * softDeletes = سلة مهملات 30 يوماً (SRS-F02-06).
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();

            $table->string('title', 190);
            $table->text('description');                     // 50–2000 حرف — تحقق على مستوى الطلب

            // حالة المشروع التجارية (SRS-F02-01) — تظهر على البطاقة
            $table->enum('status', ['completed', 'needs_development', 'needs_funding'])
                ->default('needs_funding');

            // حالة النشر/العرض — منفصلة عن status (docs/api/enums.md §1.2-1.3)
            $table->enum('publication_status', ['draft', 'published', 'archived'])
                ->default('published');

            $table->json('tags')->nullable();                // حتى 10 وسوم تقنيات
            $table->string('github_url', 255)->nullable();
            $table->string('video_url', 255)->nullable();    // YouTube / Vimeo فقط
            $table->enum('video_provider', ['youtube', 'vimeo'])->nullable();

            $table->decimal('budget_min', 12, 2)->nullable();
            $table->decimal('budget_max', 12, 2)->nullable();

            // مستوى الإفصاح عن تقرير AI: 1 زائر | 2 مسجل | 3 بعد الاتفاق
            // الافتراضي 2 (مسجّل) — المسجّل يرى الأبعاد + الرادار افتراضياً (US-038 AC2 · T127)
            $table->unsignedTinyInteger('visibility_level')->default(2);

            // مرآة لآخر تقييم مكتمل — للعرض والفرز السريع (SRS-F06-04)
            $table->decimal('ai_score', 5, 2)->nullable();   // 0.00–100.00
            $table->unsignedBigInteger('view_count')->default(0); // الفرز حسب المشاهدات
            $table->timestamp('last_evaluation_at')->nullable();  // كاش الـ 24 ساعة (SRS-AI-C01)

            $table->timestamps();
            $table->softDeletes();                           // سلة مهملات 30 يوماً (SRS-F02-06)

            // ——— الفهارس ———
            $table->index(['user_id', 'deleted_at']);        // مشاريع المالك + سلة المهملات
            $table->index(['category_id', 'deleted_at']);    // فلترة التصنيف
            $table->index(['status', 'ai_score']);           // الفرز حسب التقييم
            $table->index(['status', 'created_at']);         // الفرز حسب الأحدث
            $table->index(['status', 'view_count']);         // الفرز حسب المشاهدات
            $table->index(['publication_status', 'deleted_at']); // معرض المشاريع المنشورة
            $table->index('created_at');                     // latest('created_at') — قائمة المشاريع العامة (SRS-F07)

        });

        // ——— قيود CHECK (MySQL 8.0.16+ فقط) ———
        // ملاحظة: Laravel 13 أزال $table->check() من Blueprint → تُنفَّذ عبر SQL خام.
        // SQLite (المستخدمة في الاختبارات) لا تدعم ALTER TABLE ADD CONSTRAINT CHECK → تُتخطَّى.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_visibility_check CHECK (visibility_level BETWEEN 1 AND 3)');
            DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_budget_min_check CHECK (budget_min IS NULL OR budget_min >= 0)');
            DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_budget_max_check CHECK (budget_max IS NULL OR budget_min IS NULL OR budget_max >= budget_min)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
