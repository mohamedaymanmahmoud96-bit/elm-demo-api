<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('subject_id')->nullable()->after('grade')->constrained('subjects')->nullOnDelete();
            $table->foreignId('grade_level_id')->nullable()->after('subject_id')->constrained('grade_levels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('grade_level_id');
            $table->dropConstrainedForeignId('subject_id');
        });
        Schema::dropIfExists('grade_levels');
    }
};
