<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32)->default('absence');
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('lesson_compliance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('missed_lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('triggered_by_lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->string('kind', 32)->default('absence');
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->unique(['student_id', 'missed_lesson_id', 'kind'], 'lesson_compliance_unique');
        });

        Schema::table('quick_lesson_codes', function (Blueprint $table) {
            $table->string('video_path')->nullable()->after('video_id');
        });

        if (Schema::hasTable('subjects')) {
            \Illuminate\Support\Facades\DB::table('subjects')
                ->whereNull('is_active')
                ->update(['is_active' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('quick_lesson_codes', function (Blueprint $table) {
            $table->dropColumn('video_path');
        });
        Schema::dropIfExists('lesson_compliance_records');
        Schema::dropIfExists('student_alerts');
    }
};
