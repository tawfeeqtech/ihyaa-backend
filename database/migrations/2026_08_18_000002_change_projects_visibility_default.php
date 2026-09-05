<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * T127 — تغيير default visibility_level من 3 (AFTER_AGREEMENT) إلى 2 (REGISTERED)
     * في الجداول المطبّقة سابقاً (الميقراشن الأصلية لا تُعدَّل بعد تطبيقها).
     *
     * MySQL: ALTER COLUMN ... SET DEFAULT.
     * SQLite: لا يدعم تغيير default عبر ALTER TABLE — هنا no-op، لأن قاعدة
     * الاختبار (fresh) تأخذ default الصحيح من migration الإنشاء المعدّلة.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE projects ALTER COLUMN visibility_level SET DEFAULT 2');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE projects ALTER COLUMN visibility_level SET DEFAULT 3');
        }
    }
};
