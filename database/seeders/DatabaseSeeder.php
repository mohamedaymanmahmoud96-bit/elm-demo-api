<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Subject;
use App\Models\TeacherSubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(GradeLevelSeeder::class);

        $admin = User::firstOrCreate(
            ['email' => 'admin@eltaisir.com'],
            [
                'name' => 'مدير النظام',
                'password' => Hash::make('password123'),
                'role' => 'admin',
            ]
        );

        $teacher = User::updateOrCreate(
            ['access_code' => 'TCH-DEMO001'],
            [
                'name' => 'أ/ محمد علم',
                'email' => 'teacher@elm.com',
                'password' => Hash::make('password123'),
                'role' => 'teacher',
            ]
        );

        $student = User::firstOrCreate(
            ['access_code' => '12345678'],
            [
                'name' => 'طالب تجريبي',
                'email' => 'student@elm.com',
                'phone' => '01012345678',
                'password' => Hash::make(Str::random(32)),
                'role' => 'student',
                'account_status' => 'active',
                'grade' => '3 ثانوي',
                'governorate' => 'القاهرة',
            ]
        );

        $subject = Subject::firstOrCreate(
            ['slug' => 'physics'],
            [
                'name' => 'الفيزياء',
                'description' => 'مادة الفيزياء للصف الثالث الثانوي',
                'is_active' => true,
            ]
        );

        $teacher->update(['subject_id' => $subject->id]);

        TeacherSubscriptionPlan::updateOrCreate(
            ['teacher_id' => $teacher->id, 'plan_type' => 'term'],
            ['label' => 'اشتراك الترم', 'price' => 450, 'duration_days' => 120, 'is_active' => true]
        );
        TeacherSubscriptionPlan::updateOrCreate(
            ['teacher_id' => $teacher->id, 'plan_type' => 'year'],
            ['label' => 'اشتراك السنة', 'price' => 900, 'duration_days' => 365, 'is_active' => true]
        );

        $physicsCourse = Course::firstOrCreate(
            ['slug' => 'physics-grade-3'],
            [
                'subject_id' => $subject->id,
                'teacher_id' => $teacher->id,
                'title' => 'فيزياء 3 ثانوي - الترم الأول',
                'description' => 'شرح شامل لمنهج الفيزياء',
                'status' => 'published',
                'price' => 450,
                'grade_level' => 'الصف الثالث الثانوي',
            ]
        );

        Enrollment::updateOrCreate(
            ['student_id' => $student->id, 'course_id' => $physicsCourse->id],
            ['status' => 'active', 'starts_at' => now(), 'expires_at' => now()->addMonths(6)]
        );

        $mathSubject = Subject::firstOrCreate(
            ['slug' => 'math'],
            [
                'name' => 'الرياضيات',
                'description' => 'مادة الرياضيات للمرحلة الثانوية',
                'is_active' => true,
            ]
        );

        Course::firstOrCreate(
            ['slug' => 'math-grade-1'],
            [
                'subject_id' => $mathSubject->id,
                'teacher_id' => $teacher->id,
                'title' => 'رياضيات 1 ثانوي - الترم الأول',
                'description' => 'شرح شامل لمنهج الرياضيات',
                'status' => 'published',
                'price' => 380,
                'grade_level' => 'الصف الأول الثانوي',
            ]
        );

        User::firstOrCreate(
            ['email' => 'finance@eltaisir.com'],
            [
                'name' => 'القسم المالي',
                'password' => Hash::make('password123'),
                'role' => 'finance',
            ]
        );

        User::firstOrCreate(
            ['email' => 'parent@eltaisir.com'],
            [
                'name' => 'ولي أمر تجريبي',
                'password' => Hash::make('password123'),
                'role' => 'parent',
            ]
        );
    }
}
