<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * جدول أدوات بلا FK عمداً (لا يمنع حذف المستخدم).
     * الصلاحية: ساعة واحدة — بفحص created_at (SRS-F01-04).
     * يُفعَّل عبر .env: AUTH_PASSWORD_RESET_TOKEN_TABLE=password_resets
     */
    public function up(): void
    {
        Schema::create('password_resets', function (Blueprint $table) {
            $table->string('email', 190)->index();
            $table->string('token', 64);                     // يُخزن hash (Str::random + Hash)
            $table->timestamp('created_at')->nullable();     // الصلاحية: ساعة واحدة (تحقق على مستوى التطبيق)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_resets');
    }
};
