<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TeacherSubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeacherSubscriptionPlanController extends Controller
{
    public function index(Request $request)
    {
        $teacherId = $request->user()->resolvedTeacherId();
        abort_unless($teacherId, 403);

        return TeacherSubscriptionPlan::query()
            ->where('teacher_id', $teacherId)
            ->orderBy('plan_type')
            ->get();
    }

    public function upsert(Request $request)
    {
        $teacherId = $request->user()->resolvedTeacherId();
        abort_unless($teacherId, 403);

        $data = $request->validate([
            'plans' => ['required', 'array', 'min:1'],
            'plans.*.plan_type' => ['required', Rule::in(['term', 'year'])],
            'plans.*.price' => ['required', 'numeric', 'min:0'],
            'plans.*.label' => ['nullable', 'string', 'max:120'],
            'plans.*.duration_days' => ['nullable', 'integer', 'min:7', 'max:400'],
            'plans.*.is_active' => ['sometimes', 'boolean'],
        ]);

        $saved = [];
        foreach ($data['plans'] as $plan) {
            $planType = $plan['plan_type'];
            $defaults = [
                'term' => ['label' => 'اشتراك الترم', 'duration_days' => 120],
                'year' => ['label' => 'اشتراك السنة', 'duration_days' => 365],
            ][$planType];

            $saved[] = TeacherSubscriptionPlan::updateOrCreate(
                ['teacher_id' => $teacherId, 'plan_type' => $planType],
                [
                    'label' => $plan['label'] ?? $defaults['label'],
                    'price' => $plan['price'],
                    'duration_days' => $plan['duration_days'] ?? $defaults['duration_days'],
                    'is_active' => $plan['is_active'] ?? true,
                ]
            );
        }

        return response()->json($saved);
    }
}
