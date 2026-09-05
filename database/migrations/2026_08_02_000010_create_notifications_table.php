<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * الإشعارات — SRS-F09. is_critical = true → بث فوري عبر Reverb.
     * أنواع الإشعارات المعتمدة: interest_received · evaluation_completed (حرجة)
     * interest_accepted · interest_rejected · interest_cancelled · project_updated · evaluation_failed.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('type', 100);      // interest_received | evaluation_completed | ...
            $table->string('title', 190);     // نص عربي جاهز للعرض
            $table->text('body')->nullable();
            $table->json('data')->nullable(); // {"project_id": 1, "interest_id": 5, "url": "/projects/1"}
            $table->boolean('is_critical')->default(false); // true → بث فوري عبر Reverb
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']); // جرس الإشعارات: آخر 5 + العدادات
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
