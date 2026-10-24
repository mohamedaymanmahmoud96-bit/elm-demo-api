<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'finance']), 403);

        return User::query()
            ->select('id', 'name', 'email', 'phone', 'role', 'grade', 'governorate', 'access_code', 'employer_teacher_id', 'created_at')
            ->with(['employerTeacher:id,name'])
            ->when($request->role, fn ($query, $role) => $query->where('role', $role))
            ->latest()
            ->paginate(min(max((int) $request->query('per_page', 100), 1), 500));
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $role = $request->input('role');
        if ($role === 'assistant') {
            abort(422, 'المساعد يُضاف من حساب المعلّم فقط ويرتبط به.');
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['teacher', 'assistant', 'finance', 'admin', 'parent', 'student'])],
            'access_code' => ['nullable', 'string', 'max:32', 'unique:users,access_code'],
            'grade' => ['nullable', 'string', 'max:80'],
            'governorate' => ['nullable', 'string', 'max:80'],
            'gender' => [
                Rule::requiredIf(in_array($role, ['teacher', 'student'], true)),
                'nullable',
                Rule::in(['male', 'female']),
            ],
            'subject_id' => [
                Rule::requiredIf($role === 'teacher'),
                'nullable',
                'exists:subjects,id',
            ],
            'grade_level_id' => [
                Rule::requiredIf($role === 'teacher'),
                'nullable',
                'exists:grade_levels,id',
            ],
        ]);
        if ($data['role'] === 'student' && empty($data['access_code'])) {
            do {
                $data['access_code'] = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            } while (User::where('access_code', $data['access_code'])->exists());
        } elseif (in_array($data['role'], ['teacher', 'assistant']) && empty($data['access_code'])) {
            $prefix = match ($data['role']) {
                'teacher' => 'TCH',
                'assistant' => 'AST',
                default => 'STD',
            };
            $data['access_code'] = $prefix.'-'.strtoupper(Str::random(8));
        }

        $user = User::create($data);

        if ($user->role === 'teacher' && ! empty($data['subject_id']) && ! empty($data['grade_level_id'])) {
            $subject = Subject::findOrFail($data['subject_id']);
            $gradeLevel = GradeLevel::findOrFail($data['grade_level_id']);
            $title = "{$subject->name} — {$gradeLevel->name}";
            $baseSlug = Str::slug($title);
            $slug = $baseSlug;
            $suffix = 0;
            while (Course::where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.(++$suffix);
            }
            Course::create([
                'subject_id' => $subject->id,
                'teacher_id' => $user->id,
                'title' => $title,
                'slug' => $slug,
                'grade_level' => $gradeLevel->name,
                'price' => 0,
                'status' => 'published',
            ]);
        }

        return response()->json(
            $user->load(['subject:id,name', 'gradeLevel:id,name']),
            201
        );
    }

    public function destroy(Request $request, User $user)
    {
        $actor = $request->user();
        abort_unless(in_array($actor->role, ['admin', 'finance'], true), 403);
        abort_if($actor->role === 'finance' && $user->role !== 'student', 403, 'حساب المالية يمكنه حذف الطلاب فقط.');
        abort_if($user->id === $actor->id, 422, 'لا يمكن حذف حسابك الحالي.');

        try {
            DB::transaction(function () use ($user): void {
                $user->tokens()->delete();
                $user->conversations()->detach();
                // تحرير الحقول الفريدة قبل الحذف حتى لا يظهر «الرقم مستخدم» عند إعادة التسجيل.
                $user->forceFill([
                    'phone' => null,
                    'email' => null,
                    'access_code' => null,
                ])->save();
                $user->delete();
            });
        } catch (QueryException $exception) {
            report($exception);

            return response()->json([
                'message' => 'تعذر حذف المستخدم من قاعدة البيانات — قد توجد سجلات مرتبطة.',
            ], 422);
        }

        return response()->json(['message' => 'تم حذف المستخدم بنجاح.']);
    }

    public function update(Request $request, User $user)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'role' => ['sometimes', Rule::in(['teacher', 'assistant', 'finance', 'admin', 'parent', 'student'])],
            'grade' => ['nullable', 'string', 'max:80'],
            'governorate' => ['nullable', 'string', 'max:80'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->update($data);

        return $user->fresh();
    }
}