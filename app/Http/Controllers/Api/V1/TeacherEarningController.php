<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TeacherEarning;
use Illuminate\Http\Request;

class TeacherEarningController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['teacher', 'admin', 'finance']), 403);

        $query = TeacherEarning::with(['student:id,name', 'payment:id,amount,paid_at']);

        if ($request->user()->role === 'teacher') {
            $query->where('teacher_id', $request->user()->id);
        }

        $total = (clone $query)->sum('amount');
        $rows = $query->latest()->paginate(20);

        return response()->json([
            'total_earnings' => round((float) $total, 2),
            'data' => $rows,
        ]);
    }
}
