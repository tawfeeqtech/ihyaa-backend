<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * رموز OTP — إصلاح أمني: نقل الرمز من plaintext (users.otp_code) إلى جدول منفصل.
     *
     * code_hash يخزّن bcrypt hash فقط — لا يُحفظ الرمز نفسه أبداً.
     * جدول users يحتفظ بأعمدته القديمة (otp_code...) حالياً — تُنقل لاحقاً.
     */
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code_hash', 255);                         // bcrypt hash — لا plaintext
            $table->enum('purpose', ['email_verification', 'password_reset']);
            $table->dateTime('expires_at');                           // now() + 60 ثانية (SRS-F01-02)
            $table->dateTime('used_at')->nullable();                  // صُرف الرمز عند التحقق
            $table->dateTime('invalidated_at')->nullable();           // أُبطل (إعادة إرسال/انتهاء)

            $table->timestamps();

            // الاستعلامات: البحث عن رمز حي (غير مستخدم وغير مُبطل) لمستخدم بغرض معيّن
            $table->index(['user_id', 'purpose', 'invalidated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
