<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * نبذة مختصرة عن المشروع (bio) — حقل إضافي إلى جانب الوصف العام (description).
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->text('bio')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('bio');
        });
    }
};
