<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
class TeacherAssistantController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->role === 'teacher', 403);

        return User::query()
            ->where('role', 'assistant')
            ->where('employer_teacher_id', $request->user()->id)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'access_code', 'created_at']);
    }

    public function store(Request $request)
    {
        $teacher = $request->user();
        abort_unless($teacher->role === 'teacher', 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8'],
            'gender' => ['required', 'in:male,female'],
        ]);

        $data['role'] = 'assistant';
        $data['employer_teacher_id'] = $teacher->id;
        $data['subject_id'] = $teacher->subject_id;
        $data['grade_level_id'] = $teacher->grade_level_id;
        $data['access_code'] = 'AST-'.strtoupper(Str::random(8));

        while (User::where('access_code', $data['access_code'])->exists()) {
            $data['access_code'] = 'AST-'.strtoupper(Str::random(8));
        }

        $assistant = User::create($data);

        return response()->json($assistant->only([
            'id', 'name', 'email', 'phone', 'access_code', 'employer_teacher_id', 'created_at',
        ]), 201);
    }

    public function destroy(Request $request, User $user)
    {
        abort_unless($request->user()->role === 'teacher', 403);
        abort_unless(
            $user->role === 'assistant' && $user->employer_teacher_id === $request->user()->id,
            403
        );
        $user->delete();

        return response()->json(['message' => 'تم حذف المساعد.']);
    }
}
