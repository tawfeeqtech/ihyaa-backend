<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EPIC-12 (US-061/062) — تحليلات المشرف.
 *
 * عمود `last_active_at` — أساس تعريف "المستخدم النشط" (سجّل دخولاً أو نفّذ
 * إجراءً مصادقاً خلال اليوم — SRS-F12-02 · admin-api.md §1).
 * فهارس داعمة: `users(role)` و `users(last_active_at)` لاستعلامات COUNT/GROUP
 * المجمّعة في AnalyticsService/ActiveUsersReport (p95 < 500ms).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_active_at')->nullable()->after('last_login_at');
            $table->index('role');
            $table->index('last_active_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_active_at']);
            $table->dropIndex(['role']);
            $table->dropColumn('last_active_at');
        });
    }
};
