<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            if (! Schema::hasColumn('evaluations', 'python_evaluation_id')) {
                $table->string('python_evaluation_id', 128)->nullable()->unique();
            }

            if (! Schema::hasColumn('evaluations', 'webhook_event_id')) {
                $table->string('webhook_event_id', 128)->nullable()->unique();
            }
        });
    }

    public function down(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            if (Schema::hasColumn('evaluations', 'webhook_event_id')) {
                $table->dropUnique(['webhook_event_id']);
                $table->dropColumn('webhook_event_id');
            }

            if (Schema::hasColumn('evaluations', 'python_evaluation_id')) {
                $table->dropUnique(['python_evaluation_id']);
                $table->dropColumn('python_evaluation_id');
            }
        });
    }
};
