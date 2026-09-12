<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            $table->string('external_idempotency_key', 128)->nullable()->unique();
            $table->string('python_evaluation_id', 128)->nullable()->unique();
            $table->string('webhook_event_id', 128)->nullable()->unique();
        });

        Schema::table('evaluation_input_snapshots', function (Blueprint $table) {
            $table->json('external_input')->nullable()->after('business_info');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_input_snapshots', function (Blueprint $table) {
            $table->dropColumn('external_input');
        });

        Schema::table('evaluations', function (Blueprint $table) {
            $table->dropUnique(['external_idempotency_key']);
            $table->dropUnique(['python_evaluation_id']);
            $table->dropUnique(['webhook_event_id']);
            $table->dropColumn(['external_idempotency_key', 'python_evaluation_id', 'webhook_event_id']);
        });
    }
};