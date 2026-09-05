<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 15 تصنيفاً عبر CategorySeeder — تصنيفات مسطحة (بلا أب/ابن) (SRS-F02-01).
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 100);                      // الاسم بالعربية
            $table->string('name_en', 100);                      // الاسم بالإنجليزية
            $table->string('slug', 120)->unique();               // ecommerce | edtech | ai ...
            $table->string('icon', 50)->nullable();              // اسم أيقونة Phosphor
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
