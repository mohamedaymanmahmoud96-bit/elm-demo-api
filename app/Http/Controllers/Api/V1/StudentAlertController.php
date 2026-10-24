<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StudentAlert;
use Illuminate\Http\Request;

class StudentAlertController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->role === 'student', 403);

        return StudentAlert::query()
            ->where('student_id', $request->user()->id)
            ->latest()
            ->limit(50)
            ->get();
    }

    public function unreadCount(Request $request)
    {
        abort_unless($request->user()->role === 'student', 403);

        return response()->json([
            'count' => StudentAlert::query()
                ->where('student_id', $request->user()->id)
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    public function markRead(Request $request, StudentAlert $alert)
    {
        abort_unless($request->user()->role === 'student', 403);
        abort_unless($alert->student_id === $request->user()->id, 403);
        $alert->update(['read_at' => now()]);

        return $alert->fresh();
    }
}
