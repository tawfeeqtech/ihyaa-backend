<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * إصلاح أمني (Fix 3): OTP لم يعد plaintext — يُخزَّن ببصمة bcrypt (60 حرفاً).
     * العمود كان char(6) (للأرقام الستة فقط) — لا يسع bcrypt hash.
     * نوسّعه إلى string(255) لاستيعاب البصمة (وحرية اختيار خوارزمية أقوى مستقبلاً).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('otp_code', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // العودة إلى 6 أرقام — يُفقد توافق bcrypt (يُستخدم فقط عند التراجع عن الإصلاح الأمني)
        Schema::table('users', function (Blueprint $table) {
            $table->char('otp_code', 6)->nullable()->change();
        });
    }
};
