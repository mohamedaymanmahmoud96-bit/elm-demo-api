<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ChecksStudentCourseAccess;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\StudentAlert;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CourseController extends Controller
{
    use ChecksStudentCourseAccess;

    public function index(Request $request)
    {
        $teacherScope = $request->user()?->resolvedTeacherId();
        $query = Course::with(['subject', 'teacher:id,name'])
            ->when($teacherScope, fn ($q) => $q->where('teacher_id', $teacherScope))
            ->when(!in_array($request->user()?->role, ['admin', 'teacher', 'finance']), fn ($q) => $q->where('status', 'published'))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->subject_id, fn ($q, $id) => $q->where('subject_id', $id));

        return $query->latest()->paginate(15);
    }

    public function show(Request $request, Course $course)
    {
        $this->ensureStudentCanAccessCourse($request, $course->id);

        return $course->load(['subject', 'teacher:id,name', 'lessons']);
    }

    public function store(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'teacher']), 403);
        $data = $request->validate([
            'subject_id' => ['required', 'exists:subjects,id'],
            'title' => ['required', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:200', 'unique:courses,slug'],
            'description' => ['nullable', 'string'],
            'thumbnail_path' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'grade_level' => ['nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'in:draft,published,archived'],
        ]);
        if ($request->user()->role === 'teacher') {
            /** @var User $teacher */
            $teacher = $request->user()->load('gradeLevel');
            abort_if(empty($teacher->subject_id), 422, 'المعلّم غير مربوط بمادة. راجع الإدارة.');
            if ((int) $data['subject_id'] !== (int) $teacher->subject_id) {
                abort(422, 'المعلّم يدرّس مادة واحدة فقط.');
            }
            $existing = Course::query()
                ->where('teacher_id', $teacher->id)
                ->where('status', '!=', 'archived')
                ->exists();
            abort_if($existing, 422, 'لديك كورس بالفعل. عدّل الكورس الحالي أو تواصل مع الإدارة.');
            $data['teacher_id'] = $teacher->id;
            if (empty($data['grade_level']) && $teacher->gradeLevel) {
                $data['grade_level'] = $teacher->gradeLevel->name;
            }
        } else {
            $data['teacher_id'] = $request->validate(['teacher_id' => ['required', 'exists:users,id']])['teacher_id'];
        }
        $data['slug'] ??= Str::slug($data['title']);
        $data['status'] ??= 'published';

        return response()->json(Course::create($data)->load('subject'), 201);
    }

    public function update(Request $request, Course $course)
    {
        abort_unless($request->user()->role === 'admin' || $course->teacher_id === $request->user()->id, 403);
        $data = $request->validate([
            'subject_id' => ['sometimes', 'exists:subjects,id'],
            'title' => ['sometimes', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'thumbnail_path' => ['nullable', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'grade_level' => ['nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'in:draft,published,archived'],
            'term_number' => ['sometimes', 'integer', 'min:1'],
            'term_status' => ['sometimes', 'in:active,review,review_upload,ended'],
            'review_lessons_count' => ['sometimes', 'integer', 'min:0', 'max:30'],
        ]);
        $course->update($data);

        return $course->fresh()->load('subject');
    }

    public function endTerm(Request $request, Course $course)
    {
        abort_unless($request->user()->role === 'admin' || $course->teacher_id === $request->user()->id, 403);
        $course->update(['term_status' => 'review']);

        $studentIds = Enrollment::query()
            ->where('course_id', $course->id)
            ->where('status', 'active')
            ->pluck('student_id');

        Enrollment::query()
            ->where('course_id', $course->id)
            ->where('status', 'active')
            ->update([
                'status' => 'expired',
                'expires_at' => now(),
            ]);

        $term = (int) ($course->term_number ?? 1);
        $courseTitle = $course->title;
        foreach ($studentIds as $studentId) {
            StudentAlert::create([
                'student_id' => $studentId,
                'course_id' => $course->id,
                'type' => 'term_ended',
                'title' => 'انتهى الترم — شكراً لاجتهادك',
                'body' => "انتهى الترم {$term} في «{$courseTitle}». نتمنى لك التوفيق دائماً. "
                    .'للمتابعة في الترم الجديد، قدّم طلب اشتراك جديد وارفع إيصال الدفع ليُفعَّل حسابك.',
            ]);
        }

        return response()->json([
            'message' => 'تم إنهاء الترم. انتهى اشتراك الطلاب في هذا الترم. حدد عدد حصص المراجعة.',
            'course' => $course->fresh(),
        ]);
    }

    public function configureReview(Request $request, Course $course)
    {
        abort_unless($request->user()->role === 'admin' || $course->teacher_id === $request->user()->id, 403);
        $data = $request->validate([
            'review_lessons_count' => ['required', 'integer', 'min:1', 'max:30'],
        ]);
        $course->update([
            'review_lessons_count' => $data['review_lessons_count'],
            'term_status' => 'review_upload',
        ]);

        return response()->json(['message' => 'ارفع حصص المراجعة الآن.', 'course' => $course->fresh()]);
    }

    public function termProgress(Course $course)
    {
        $term = (int) ($course->term_number ?? 1);
        $required = (int) ($course->review_lessons_count ?? 0);
        $uploaded = $course->lessons()
            ->where('is_review', true)
            ->where('term_number', $term)
            ->count();

        return response()->json([
            'course' => $course->fresh(),
            'term_number' => $term,
            'review_required' => $required,
            'review_uploaded' => $uploaded,
            'review_complete' => $required > 0 && $uploaded >= $required,
        ]);
    }

    public function startNextTerm(Request $request, Course $course)
    {
        abort_unless($request->user()->role === 'admin' || $course->teacher_id === $request->user()->id, 403);
        abort_unless(in_array($course->term_status, ['review_upload', 'ended', 'review']), 422, 'يجب إنهاء الترم ورفع حصص المراجعة أولاً.');

        $term = (int) ($course->term_number ?? 1);
        $required = (int) ($course->review_lessons_count ?? 0);
        if ($required > 0) {
            $uploaded = $course->lessons()->where('is_review', true)->where('term_number', $term)->count();
            abort_if($uploaded < $required, 422, "يجب رفع {$required} حصص مراجعة (تم رفع {$uploaded}).");
        }

        $course->update([
            'term_number' => ($course->term_number ?? 1) + 1,
            'term_status' => 'active',
            'review_lessons_count' => 0,
        ]);

        return response()->json(['message' => 'بدأ الترم الجديد.', 'course' => $course->fresh()]);
    }

    public function destroy(Request $request, Course $course)
    {
        abort_unless($request->user()->role === 'admin' || $course->teacher_id === $request->user()->id, 403);
        $course->delete();

        return response()->json(['message' => 'تم حذف الكورس.']);
    }
}
