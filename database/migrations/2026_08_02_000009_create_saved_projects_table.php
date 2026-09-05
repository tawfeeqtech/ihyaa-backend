<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * المشاريع المحفوظة — SRS-F11-04: حفظ واحد فقط لكل مستثمر/مشروع. بلا notes.
     */
    public function up(): void
    {
        Schema::create('saved_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();     // مستثمر
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'project_id']); // حفظ واحد فقط لكل مستثمر/مشروع (SRS-F11-04)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_projects');
    }
};
