<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GradeLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GradeLevelController extends Controller
{
    public function index()
    {
        return GradeLevel::query()->orderBy('sort_order')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:140', 'unique:grade_levels,slug'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
        $data['slug'] ??= Str::slug($data['name']);
        $data['sort_order'] ??= (int) GradeLevel::max('sort_order') + 1;

        return response()->json(GradeLevel::create($data), 201);
    }

    public function update(Request $request, GradeLevel $gradeLevel)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'slug' => ['sometimes', 'string', 'max:140', 'unique:grade_levels,slug,'.$gradeLevel->id],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
        $gradeLevel->update($data);

        return $gradeLevel->fresh();
    }

    public function destroy(Request $request, GradeLevel $gradeLevel)
    {
        abort_unless($request->user()->role === 'admin', 403);
        abort_if($gradeLevel->teachers()->exists(), 422, 'لا يمكن حذف صف مرتبط بمعلمين.');
        $gradeLevel->delete();

        return response()->json(['message' => 'تم حذف الصف الدراسي.']);
    }
}
