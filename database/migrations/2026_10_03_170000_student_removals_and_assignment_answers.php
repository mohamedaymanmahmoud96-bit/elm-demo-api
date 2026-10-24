<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_removal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->string('status')->default('pending')->index();
            $table->timestamps();
        });

        Schema::table('assignment_submissions', function (Blueprint $table) {
            $table->json('answers')->nullable()->after('answer');
            $table->json('auto_review')->nullable()->after('feedback');
        });
    }

    public function down(): void
    {
        Schema::table('assignment_submissions', function (Blueprint $table) {
            $table->dropColumn(['answers', 'auto_review']);
        });
        Schema::dropIfExists('student_removal_requests');
    }
};
