<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * الملفات الشخصية الموسّعة — جدول منفصل عن users (1:1).
     *
     * الفصل يحسّن الخصوصية (بيانات الملف لا تُسحب مع كل استعلام مصادقة)
     * ويسمح بتوسّع حقول كل دور دون تلويث جدول الهوية.
     * جدول users يحتفظ بحقوله المكررة (bio, avatar_path...) حالياً — تُنقل لاحقاً.
     */
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // ——— حقول مشتركة ———
            $table->text('bio')->nullable();
            $table->string('avatar_url', 500)->nullable();
            $table->json('skills')->nullable();                     // ["laravel", "react", "ai"]
            $table->json('social_links')->nullable();               // {"github": "...", "linkedin": "..."}

            // ——— ملف صاحب الفكرة (idea_owner) ———
            $table->string('university', 150)->nullable();
            $table->string('major', 150)->nullable();

            // ——— ملف المستثمر (investor) ———
            $table->string('investment_focus', 150)->nullable();
            $table->json('investment_range')->nullable();           // {"min": 50000, "max": 500000}
            $table->json('preferred_sectors')->nullable();          // ["التقنية المالية", "الذكاء الاصطناعي"]

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
