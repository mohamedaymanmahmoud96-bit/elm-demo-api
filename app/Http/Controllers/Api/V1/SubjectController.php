<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SubjectController extends Controller
{
    public function index()
    {
        return Subject::query()->where('is_active', true)->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'assistant']), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:140', 'unique:subjects,slug'],
            'description' => ['nullable', 'string'],
        ]);
        $data['slug'] ??= Str::slug($data['name']);
        $data['is_active'] = true;

        return response()->json(Subject::create($data), 201);
    }

    public function update(Request $request, Subject $subject)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'assistant']), 403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'slug' => ['sometimes', 'string', 'max:140', 'unique:subjects,slug,'.$subject->id],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $subject->update($data);
        return $subject->fresh();
    }

    public function destroy(Request $request, Subject $subject)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $subject->delete();
        return response()->noContent();
    }
}