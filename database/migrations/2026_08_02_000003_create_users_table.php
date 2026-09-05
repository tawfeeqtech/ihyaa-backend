<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * المستخدمون — الهوية والمصادقة (SRS-F01) + الملف الشخصي حسب الدور.
     *
     * ملاحظة تصميم: عمود `role` (enum قابل للتعديل مرة واحدة فقط من API عند أول دخول OAuth)
     * هو المصدر الأساسي لدور المستخدم (تستخدمه الـ Middleware والـ Rate Limiters).
     * جدول `roles` + pivot `role_user` مرجعيان (يُنشآن في 2026_08_02_000001).
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // ——— الهوية والمصادقة ———
            $table->string('name', 100);
            $table->string('email', 190)->unique();
            $table->string('password')->nullable();          // nullable: حسابات OAuth فقط
            $table->timestamp('email_verified_at')->nullable(); // التفعيل إلزامي (SRS-F01-02)
            $table->rememberToken();
            $table->boolean('is_active')->default(true);

            // ——— الدور (idea_owner | investor | admin) ———
            // nullable: مستخدم OAuth جديد لم يختر الدور بعد (SRS-F01-07)
            $table->enum('role', ['idea_owner', 'investor', 'admin'])->nullable();

            // ——— OAuth (Google / GitHub / LinkedIn — SRS-F01-07) ———
            $table->string('provider', 30)->nullable();      // google | github | linkedin
            $table->string('provider_id', 190)->nullable();

            // ——— التحقق بالبريد OTP (6 أرقام — صلاحية دقيقة واحدة) ———
            $table->char('otp_code', 6)->nullable();         // 6 أرقام — التحقق من الصيغة على مستوى الطلب
            $table->timestamp('otp_expires_at')->nullable(); // now() + 60 ثانية
            $table->unsignedTinyInteger('otp_attempts')->default(0); // حظر بعد 3 محاولات خاطئة
            $table->timestamp('otp_last_sent_at')->nullable();      // حد الإعادة: 3/دقيقة (rate limit)

            // ——— الملف الشخصي — حقول مشتركة ———
            $table->string('avatar_path', 255)->nullable();
            $table->text('bio')->nullable();

            // ——— ملف صاحب الفكرة (idea_owner) ———
            $table->string('university', 190)->nullable();
            $table->string('major', 190)->nullable();

            // ——— ملف المستثمر (investor) ———
            $table->string('investment_focus', 190)->nullable();
            $table->json('investment_range')->nullable();    // {"min": 50000, "max": 500000}
            $table->json('preferred_sectors')->nullable();   // ["التقنية المالية", "الذكاء الاصطناعي"]

            $table->timestamp('last_login_at')->nullable();  // تحليلات النشاط — آخر 7 أيام (SRS-F12-02)
            $table->timestamps();

            // حساب OAuth واحد لكل مزود
            $table->unique(['provider', 'provider_id']);
        });

        // Pivot الأدوار — يُنشأ هنا بعد users (يحمل FK إلى users و roles)
        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('users');
    }
};
