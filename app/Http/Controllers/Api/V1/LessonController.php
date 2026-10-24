<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ChecksStudentCourseAccess;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use App\Services\LessonComplianceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

class LessonController extends Controller
{
    use ChecksStudentCourseAccess;

    public function index(Request $request, Course $course)
    {
        $this->ensureStudentCanAccessCourse($request, $course->id);

        return $course->lessons()->get();
    }

    public function store(Request $request, Course $course)
    {
        abort_unless($request->user()->managesTeacher($course->teacher_id), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'video' => ['nullable', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime,video/x-msvideo', 'max:512000'],
            'video_provider' => ['nullable', 'in:cloudflare_stream,bunny,youtube,upload'],
            'video_id' => ['nullable', 'string', 'max:255'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_preview' => ['sometimes', 'boolean'],
            'published_at' => ['nullable', 'date'],
        ]);

        if ($request->hasFile('video')) {
            $storedPath = $request->file('video')->store(
                'lesson-videos/'.$course->id,
                'public'
            );
            $data['video_path'] = $storedPath;
            $data['video_provider'] = 'upload';
            $data['video_id'] = basename($storedPath);
        }

        $data['course_id'] = $course->id;

        if ($course->term_status === 'review_upload') {
            $data['is_review'] = true;
            $data['term_number'] = (int) ($course->term_number ?? 1);
        } else {
            $data['is_review'] = $request->boolean('is_review', false);
            $data['term_number'] = (int) ($request->input('term_number', $course->term_number ?? 1));
        }

        $lesson = Lesson::create($data);
        if ($lesson->published_at !== null) {
            app(LessonComplianceService::class)->onLessonPublished($lesson);
        }

        return response()->json($lesson, 201);
    }

    public function update(Request $request, Lesson $lesson)
    {
        abort_unless($request->user()->managesTeacher($lesson->course->teacher_id), 403);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'video' => ['nullable', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime,video/x-msvideo', 'max:512000'],
            'video_provider' => ['nullable', 'in:cloudflare_stream,bunny,youtube,upload'],
            'video_id' => ['nullable', 'string', 'max:255'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_preview' => ['sometimes', 'boolean'],
            'published_at' => ['nullable', 'date'],
        ]);

        if ($request->hasFile('video')) {
            if ($lesson->video_path) {
                Storage::disk('public')->delete($lesson->video_path);
            }
            $storedPath = $request->file('video')->store(
                'lesson-videos/'.$lesson->course_id,
                'public'
            );
            $data['video_path'] = $storedPath;
            $data['video_provider'] = 'upload';
            $data['video_id'] = basename($storedPath);
        }

        $lesson->update($data);

        return $lesson->fresh();
    }

    public function destroy(Request $request, Lesson $lesson)
    {
        abort_unless($request->user()->managesTeacher($lesson->course->teacher_id), 403);

        if ($lesson->video_path) {
            Storage::disk('public')->delete($lesson->video_path);
        }

        $lesson->delete();

        return response()->noContent();
    }

    public function streamVideo(Request $request, Lesson $lesson)
    {
        abort_unless($lesson->video_path, 404, 'لا يوجد فيديو مرفوع لهذه الحصة.');

        $user = $this->resolveStreamUser($request);
        abort_unless($user, 401, 'يجب تسجيل الدخول لمشاهدة الحصة.');

        $canWatch = $lesson->is_preview
            || in_array($user->role, ['admin', 'teacher', 'assistant', 'finance'], true)
            || ($user->role === 'student' && Enrollment::query()
                ->where('student_id', $user->id)
                ->where('course_id', $lesson->course_id)
                ->where('status', 'active')
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->exists());

        abort_unless($canWatch, 403, 'يجب الاشتراك في الكورس لمشاهدة هذه الحصة.');

        $path = Storage::disk('public')->path($lesson->video_path);
        abort_unless(is_file($path), 404, 'ملف الفيدio غير موجود على الخادم.');

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            default => 'video/mp4',
        };

        return response()->file($path, [
            'Content-Type' => $mime,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function resolveStreamUser(Request $request): ?User
    {
        if ($request->user() instanceof User) {
            return $request->user();
        }

        $plain = $request->bearerToken() ?? $request->query('token');
        if (! is_string($plain) || $plain === '') {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($plain);

        return $accessToken?->tokenable instanceof User ? $accessToken->tokenable : null;
    }
}
