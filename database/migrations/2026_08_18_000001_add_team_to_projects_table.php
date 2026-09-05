<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * أعضاء الفريق — JSON [{name, role}] (SRS-F02-01 — حقول اختيارية للمشروع).
     * تُستهلك لاحقاً من محرك تقييم AI (بُعد الفريق) ولوحة صاحب الفكرة.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('team')->nullable()->after('tags');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('team');
        });
    }
};
