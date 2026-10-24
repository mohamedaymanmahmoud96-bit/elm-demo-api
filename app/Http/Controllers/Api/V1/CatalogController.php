<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\User;
use App\Support\GradeMatcher;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function courses(Request $request)
    {
        $studentGrade = $request->query('grade');

        $courses = Course::query()
            ->with(['subject:id,name,slug', 'teacher:id,name,grade_level_id', 'teacher.gradeLevel:id,name'])
            ->where('status', 'published')
            ->orderBy('subject_id')
            ->get()
            ->when($studentGrade, function ($collection) use ($studentGrade) {
                return $collection->filter(function (Course $course) use ($studentGrade) {
                    if (GradeMatcher::matches($course->grade_level, $studentGrade)) {
                        return true;
                    }
                    $teacherGrade = $course->teacher?->gradeLevel?->name;

                    return GradeMatcher::matches($teacherGrade, $studentGrade)
                        || GradeMatcher::matches($course->title, $studentGrade);
                });
            })
            ->values()
            ->map(fn (Course $course) => [
                'id' => $course->id,
                'title' => $course->title,
                'description' => $course->description,
                'price' => (float) $course->price,
                'subject' => $course->subject,
                'teacher' => $course->teacher,
            ]);

        return response()->json($courses);
    }

    public function subjects()
    {
        return Subject::query()
            ->where('is_active', true)
            ->with(['courses' => fn ($q) => $q->where('status', 'published')->with('teacher:id,name')])
            ->orderBy('name')
            ->get();
    }

    public function gradeLevels()
    {
        return GradeLevel::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'slug']);
    }

    /** اشتراك: مادة → معلمون → أسعار الترم / السنة (بدون «كورس» في الواجهة). */
    public function subscriptionOffers(Request $request)
    {
        $studentGrade = $request->query('grade');

        $subjects = Subject::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $offers = [];
        foreach ($subjects as $subject) {
            $teachers = User::query()
                ->where('role', 'teacher')
                ->where('subject_id', $subject->id)
                ->with([
                    'gradeLevel:id,name',
                    'subscriptionPlans' => fn ($q) => $q->where('is_active', true)->orderBy('plan_type'),
                ])
                ->orderBy('name')
                ->get()
                ->filter(function (User $teacher) use ($studentGrade) {
                    if (! $studentGrade) {
                        return true;
                    }

                    return GradeMatcher::matches($teacher->gradeLevel?->name, $studentGrade);
                })
                ->map(function (User $teacher) {
                    return [
                        'id' => $teacher->id,
                        'name' => $teacher->name,
                        'grade_level' => $teacher->gradeLevel?->name,
                        'plans' => $teacher->subscriptionPlans->map(fn ($plan) => [
                            'plan_type' => $plan->plan_type,
                            'label' => $plan->label,
                            'price' => (float) $plan->price,
                            'duration_days' => (int) $plan->duration_days,
                        ])->values(),
                    ];
                })
                ->filter(fn (array $row) => $row['plans']->isNotEmpty())
                ->values();

            if ($teachers->isNotEmpty()) {
                $offers[] = [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'teachers' => $teachers,
                ];
            }
        }

        return response()->json($offers);
    }
}
