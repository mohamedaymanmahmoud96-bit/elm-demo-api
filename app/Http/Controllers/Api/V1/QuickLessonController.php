<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuickLessonController extends Controller
{
    public function create(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['teacher', 'assistant', 'admin'], true), 403);
        $teacherId = $request->user()->resolvedTeacherId() ?? $request->user()->id;

        $data = $request->validate([
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'title' => ['required_without:lesson_id', 'nullable', 'string', 'max:180'],
            'video_provider' => ['nullable', 'in:cloudflare_stream,bunny,youtube,upload'],
            'video_id' => ['nullable', 'string', 'max:255'],
            'video' => ['nullable', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:512000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $lessonId = $data['lesson_id'] ?? null;
        $title = trim((string) ($data['title'] ?? ''));
        $videoPath = null;
        $videoProvider = $data['video_provider'] ?? null;
        $videoId = $data['video_id'] ?? null;

        if ($lessonId) {
            $lesson = Lesson::query()->with('course')->findOrFail($lessonId);
            abort_unless($request->user()->managesTeacher($lesson->course->teacher_id), 403);
            $teacherId = (int) $lesson->course->teacher_id;
            $title = $lesson->title;
            $videoProvider = $lesson->video_provider;
            $videoId = $lesson->video_id;
            $videoPath = $lesson->video_path;
        } elseif ($request->hasFile('video')) {
            $videoPath = $request->file('video')->store('quick-lesson-videos', 'public');
            $videoProvider = 'upload';
            $videoId = basename($videoPath);
        }

        abort_if($title === '', 422, 'اختر حصة من المنصة أو أدخل عنوان الحصة.');
        abort_if(empty($videoId) && empty($videoPath), 422, 'اختر حصة لها فيديو أو ارفع ملف فيديو.');

        $expiresAt = $data['expires_at'] ?? now()->addDays(3);

        $id = DB::table('quick_lesson_codes')->insertGetId([
            'code' => $this->newCode(),
            'teacher_id' => $teacherId,
            'lesson_id' => $lessonId,
            'title' => $title,
            'video_provider' => $videoProvider,
            'video_id' => $videoId,
            'video_path' => $videoPath,
            'expires_at' => $expiresAt,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(DB::table('quick_lesson_codes')->find($id), 201);
    }

    public function resolve(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'digits:8'],
            'viewer_key' => ['nullable', 'string', 'max:80'],
        ]);

        $lesson = $this->findActiveCode($data['code']);
        if (! $lesson) {
            return response()->json(['message' => 'كود الحصة غير صحيح أو منتهي الصلاحية.'], 404);
        }

        $viewerKey = $this->resolveViewerKey($request, $data['viewer_key'] ?? null);
        if ($this->hasCompletedView((int) $lesson->id, $viewerKey)) {
            return response()->json([
                'message' => 'شاهدت هذه الحصة من قبل — انتهى الكود لحسابك ولا يمكن إعادة الدخول.',
            ], 403);
        }

        $payload = $this->videoPayload($lesson);

        return response()->json([
            'lesson' => [
                'title' => $lesson->title,
                'video_provider' => $payload['video_provider'],
                'video_id' => $payload['video_id'],
                'video_path' => $payload['video_path'],
                'lesson_id' => $lesson->lesson_id,
                'quick_lesson_code' => $lesson->code,
            ],
        ]);
    }

    public function complete(Request $request, string $code)
    {
        $data = $request->validate([
            'viewer_key' => ['nullable', 'string', 'max:80'],
        ]);

        $row = $this->findActiveCode($code);
        abort_unless($row, 404, 'كود الحصة غير صحيح أو منتهي.');

        $viewerKey = $this->resolveViewerKey($request, $data['viewer_key'] ?? null);
        abort_if(
            $this->hasCompletedView((int) $row->id, $viewerKey),
            422,
            'تم تسجيل مشاهدة هذه الحصة مسبقاً.'
        );

        DB::table('quick_lesson_views')->updateOrInsert(
            [
                'quick_lesson_code_id' => $row->id,
                'viewer_key' => $viewerKey,
            ],
            [
                'student_id' => $request->user()?->id,
                'completed_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'تم إكمال الحصة. الكود لم يعد متاحاً لحسابك.',
        ]);
    }

    public function streamVideo(Request $request, string $code)
    {
        $row = DB::table('quick_lesson_codes')
            ->where('code', $code)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        abort_unless($row, 404);

        $payload = $this->videoPayload($row);
        abort_unless($payload['video_path'] || $payload['video_id'], 404);

        if ($payload['video_path']) {
            $path = storage_path('app/public/'.$payload['video_path']);
            abort_unless(is_file($path), 404);

            return response()->file($path, ['Accept-Ranges' => 'bytes']);
        }

        abort(404, 'استخدم رابط الفيديو الخارجي.');
    }

    private function findActiveCode(string $code): ?object
    {
        return DB::table('quick_lesson_codes')
            ->where('code', $code)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    private function resolveViewerKey(Request $request, ?string $viewerKey): string
    {
        if ($request->user()?->role === 'student') {
            return 'student:'.$request->user()->id;
        }
        $viewerKey = trim((string) $viewerKey);
        abort_if($viewerKey === '', 422, 'معرّف المشاهدة مطلوب.');

        return 'guest:'.$viewerKey;
    }

    private function hasCompletedView(int $quickLessonId, string $viewerKey): bool
    {
        return DB::table('quick_lesson_views')
            ->where('quick_lesson_code_id', $quickLessonId)
            ->where('viewer_key', $viewerKey)
            ->whereNotNull('completed_at')
            ->exists();
    }

    private function videoPayload(object $row): array
    {
        $videoProvider = $row->video_provider ?? null;
        $videoId = $row->video_id ?? null;
        $videoPath = $row->video_path ?? null;

        if (! empty($row->lesson_id) && empty($videoPath) && empty($videoId)) {
            $platformLesson = Lesson::query()->find($row->lesson_id);
            if ($platformLesson) {
                $videoProvider = $platformLesson->video_provider;
                $videoId = $platformLesson->video_id;
                $videoPath = $platformLesson->video_path;
            }
        }

        return [
            'video_provider' => $videoProvider,
            'video_id' => $videoId,
            'video_path' => $videoPath,
        ];
    }

    private function newCode(): string
    {
        do {
            $code = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        } while (DB::table('quick_lesson_codes')->where('code', $code)->exists());

        return $code;
    }
}
