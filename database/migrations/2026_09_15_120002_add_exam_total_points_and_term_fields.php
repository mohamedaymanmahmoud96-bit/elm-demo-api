<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->decimal('total_points', 8, 2)->default(100)->after('duration_minutes');
            $table->string('exam_mode')->default('mixed')->after('total_points'); // mixed|mcq|essay
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->unsignedTinyInteger('term_number')->default(1)->after('price');
            $table->string('term_status')->default('active')->after('term_number'); // active|review|ended
            $table->unsignedTinyInteger('review_lessons_count')->default(0)->after('term_status');
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn(['total_points', 'exam_mode']);
        });
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['term_number', 'term_status', 'review_lessons_count']);
        });
    }
};
