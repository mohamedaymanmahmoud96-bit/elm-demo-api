<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_status')->default('pending')->after('role')->index();
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->string('grade_level')->nullable()->after('price')->index();
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->boolean('is_review')->default(false)->after('is_preview');
            $table->unsignedInteger('term_number')->default(1)->after('is_review');
        });

        Schema::table('exams', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_attempts')->default(1)->after('duration_minutes');
        });

        Schema::create('teacher_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->json('course_ids')->nullable();
            $table->string('status')->default('recorded')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_earnings');
        Schema::table('exams', fn (Blueprint $table) => $table->dropColumn('max_attempts'));
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['is_review', 'term_number']);
        });
        Schema::table('courses', fn (Blueprint $table) => $table->dropColumn('grade_level'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('account_status'));
    }
};
