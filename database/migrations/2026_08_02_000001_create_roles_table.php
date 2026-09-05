<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * الأدوار الثلاثة: idea_owner | investor | admin — تُزرع عبر RoleSeeder فقط.
     * admin لا يُنشأ بالتسجيل العام (SRS §1.2).
     */
    /**
     * ملاحظة الترتيب: pivot `role_user` يُنشأ في ملف users migration
     * (2026_08_02_000003) لأنه يحمل FK إلى users — ويأتي بعد roles هنا.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();        // idea_owner | investor | admin
            $table->string('display_name', 50);          // صاحب فكرة | مستثمر | مشرف
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
