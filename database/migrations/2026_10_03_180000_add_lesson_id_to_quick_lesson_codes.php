<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quick_lesson_codes', function (Blueprint $table) {
            $table->foreignId('lesson_id')->nullable()->after('teacher_id')->constrained('lessons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quick_lesson_codes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lesson_id');
        });
    }
};
