<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quick_lesson_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quick_lesson_code_id')->constrained('quick_lesson_codes')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('viewer_key', 80);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['quick_lesson_code_id', 'viewer_key'], 'quick_lesson_viewer_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_lesson_views');
    }
};
