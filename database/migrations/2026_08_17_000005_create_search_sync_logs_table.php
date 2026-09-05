<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * أخطاء مزامنة فهرس Meilisearch ومراقبة المشرف — data-model.md §6 (US-034-S5).
     * سجل مرن متعدد الأشكال (indexable_type/indexable_id) — بلا FK صريح.
     */
    public function up(): void
    {
        Schema::create('search_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('indexable_type', 191);             // App\Models\Project
            $table->unsignedBigInteger('indexable_id');

            $table->enum('action', ['searchable', 'unsearchable', 'rebuild']);
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('resolved_at')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // ——— فهارس §6 ———
            $table->index(['status', 'created_at'], 'idx_sync_status');
            $table->index(['indexable_type', 'indexable_id'], 'idx_sync_indexable');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_sync_logs');
    }
};
