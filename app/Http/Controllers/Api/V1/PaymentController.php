<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\TeacherSubscriptionPlan;
use App\Models\User;
use App\Notifications\PaymentReviewedNotification;
use App\Services\PaymobService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PaymentController extends Controller
{
    public function plans()
    {
        return response()->json([
            [
                'id' => 'semester',
                'label' => ['ar' => 'اشتراك ترم', 'en' => 'Semester subscription'],
                'type' => 'semester',
                'price' => 450,
                'duration_days' => 120,
                'currency' => 'EGP',
            ],
            [
                'id' => 'annual',
                'label' => ['ar' => 'اشتراك سنوي', 'en' => 'Annual subscription'],
                'type' => 'annual',
                'price' => 900,
                'duration_days' => 365,
                'currency' => 'EGP',
            ],
            [
                'id' => 'chapter',
                'label' => ['ar' => 'اشتراك باب', 'en' => 'Chapter subscription'],
                'type' => 'chapter',
                'price' => 180,
                'duration_days' => 45,
                'currency' => 'EGP',
            ],
            [
                'id' => 'unit',
                'label' => ['ar' => 'اشتراك وحدة', 'en' => 'Unit subscription'],
                'type' => 'unit',
                'price' => 90,
                'duration_days' => 30,
                'currency' => 'EGP',
            ],
        ]);
    }

    public function index(Request $request)
    {
        $query = Payment::with(['student:id,name,email', 'enrollment.course:id,title']);
        if ($request->user()->role === 'student') {
            $query->where('student_id', $request->user()->id);
        } elseif (!in_array($request->user()->role, ['admin', 'finance', 'teacher', 'assistant'])) {
            abort(403);
        }

        return $query->latest()->paginate(15);
    }

    public function store(Request $request, Enrollment $enrollment)
    {
        abort_unless($request->user()->role === 'student' && $enrollment->student_id === $request->user()->id, 403);
        $data = $request->validate([
            'provider' => ['sometimes', 'in:vodafone_cash'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'transaction_id' => ['required', 'string', 'max:255'],
            'receipt_path' => ['nullable', 'string', 'max:255'],
        ]);
        $data['student_id'] = $request->user()->id;
        $data['enrollment_id'] = $enrollment->id;
        $data['status'] = 'pending';

        return response()->json(Payment::create($data), 201);
    }

    public function createManualRequest(Request $request)
    {
        abort_unless($request->user()->role === 'student', 403);

        $data = $request->validate([
            'plan_type' => ['nullable', 'string', 'in:semester,annual,chapter,unit,custom,term,year'],
            'amount' => ['required', 'numeric', 'min:0'],
            'account_owner_name' => ['required', 'string', 'max:120'],
            'account_phone' => ['required', 'string', 'max:30'],
            'wallet_phone' => ['nullable', 'string', 'max:30'],
            'course_ids' => ['required_without:subscription_items', 'array'],
            'course_ids.*' => ['integer', 'exists:courses,id'],
            'subscription_items' => ['required_without:course_ids', 'array', 'min:1'],
            'subscription_items.*.subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'subscription_items.*.teacher_id' => ['required', 'integer', 'exists:users,id'],
            'subscription_items.*.plan_type' => ['required', 'string', 'in:term,year'],
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'receipt_path' => ['nullable', 'string', 'max:255'],
        ]);

        if (! empty($data['subscription_items'])) {
            $checkout = $this->buildCheckoutFromSubscriptionItems($data['subscription_items']);
            $courses = $checkout['courses'];
            $expectedAmount = $checkout['total'];
            $teacherPayouts = $checkout['teacher_payouts'];
            $subscriptionSelections = $checkout['selections'];
        } else {
            $courses = Course::query()
                ->with('teacher:id,name')
                ->whereIn('id', $data['course_ids'])
                ->where('status', 'published')
                ->get();

            abort_unless($courses->count() === count($data['course_ids']), 422, 'بعض المواد المختارة غير متاحة.');

            $expectedAmount = round($courses->sum('price'), 2);
            $teacherPayouts = $courses->groupBy('teacher_id')->map(function ($group, $teacherId) {
                return [
                    'teacher_id' => (int) $teacherId,
                    'teacher_name' => $group->first()->teacher?->name,
                    'amount' => round($group->sum('price'), 2),
                    'course_ids' => $group->pluck('id')->values()->all(),
                    'duration_days' => 120,
                ];
            })->values()->all();
            $subscriptionSelections = [];
        }

        abort_if(abs($expectedAmount - (float) $data['amount']) > 0.01, 422, 'المبلغ لا يطابق مجموع الاشتراكات المختارة.');

        $receiptPath = $data['receipt_path'] ?? null;
        $uploadedReceipt = $request->file('receipt');

        if ($uploadedReceipt instanceof \Illuminate\Http\UploadedFile) {
            $storedPath = $uploadedReceipt->storeAs(
                'payment-receipts/'.$request->user()->id,
                'receipt-'.time().'-'.$request->user()->id.'.'.$uploadedReceipt->getClientOriginalExtension(),
                'public'
            );

            $receiptPath = $storedPath ?: null;
        }

        abort_if(empty($receiptPath), 422, 'يجب رفع إيصال الدفع (صورة أو PDF).');
        $receiptPublicUrl = url(Storage::url($receiptPath));

        $payment = Payment::create([
            'student_id' => $request->user()->id,
            'amount' => $data['amount'],
            'currency' => 'EGP',
            'provider' => 'vodafone_cash',
            'transaction_id' => 'manual-'.time().'-'.$request->user()->id,
            'receipt_path' => $receiptPublicUrl,
            'status' => 'pending',
            'metadata' => [
                'subscription_type' => 'student_course',
                'plan_type' => $data['plan_type'] ?? 'custom',
                'account_owner_name' => $data['account_owner_name'],
                'account_phone' => $data['account_phone'],
                'wallet_phone' => $data['wallet_phone'] ?? $data['account_phone'],
                'course_ids' => $courses->pluck('id')->values()->all(),
                'subscription_items' => $subscriptionSelections,
                'courses' => $courses->map(function (Course $c) use ($subscriptionSelections) {
                    $duration = 120;
                    $price = (float) $c->price;
                    foreach ($subscriptionSelections as $sel) {
                        if ((int) ($sel['course_id'] ?? 0) === (int) $c->id) {
                            $duration = (int) ($sel['duration_days'] ?? 120);
                            $price = (float) ($sel['price'] ?? $price);
                            break;
                        }
                    }

                    return [
                        'id' => $c->id,
                        'title' => $c->title,
                        'price' => $price,
                        'teacher_id' => $c->teacher_id,
                        'teacher_name' => $c->teacher?->name,
                        'duration_days' => $duration,
                    ];
                })->values()->all(),
                'teacher_payouts' => $teacherPayouts,
                'teacher_ids' => collect($teacherPayouts)->pluck('teacher_id')->unique()->values()->all(),
                'teacher_approvals' => [],
                'receipt_path' => $receiptPublicUrl,
                'receipt_storage_path' => $receiptPath,
                'manual_review' => true,
                'subscription_status' => 'pending',
                'subscription_started_at' => null,
                'subscription_expires_at' => null,
            ],
        ]);

        return response()->json($payment->fresh(), 201);
    }

    public function createTeacherPlatformRequest(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['teacher', 'assistant']), 403);

        $data = $request->validate([
            'plan_type' => ['required', 'string', 'in:semester,annual'],
            'amount' => ['required', 'numeric', 'min:0'],
            'account_owner_name' => ['required', 'string', 'max:120'],
            'account_phone' => ['required', 'string', 'max:30'],
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $uploadedReceipt = $request->file('receipt');
        $storedPath = $uploadedReceipt->storeAs(
            'teacher-subscriptions/'.$request->user()->id,
            'receipt-'.time().'-'.$request->user()->id.'.'.$uploadedReceipt->getClientOriginalExtension(),
            'public'
        );
        $receiptPath = $storedPath ? url(Storage::url($storedPath)) : null;
        abort_if(empty($receiptPath), 422, 'يجب رفع إيصال الدفع.');

        $payment = Payment::create([
            'student_id' => $request->user()->id,
            'amount' => $data['amount'],
            'currency' => 'EGP',
            'provider' => 'vodafone_cash',
            'transaction_id' => 'teacher-platform-'.time().'-'.$request->user()->id,
            'receipt_path' => $receiptPath,
            'status' => 'pending',
            'metadata' => [
                'subscription_type' => 'teacher_platform',
                'plan_type' => $data['plan_type'],
                'account_owner_name' => $data['account_owner_name'],
                'account_phone' => $data['account_phone'],
                'teacher_id' => $request->user()->id,
                'teacher_name' => $request->user()->name,
                'receipt_path' => $receiptPath,
                'manual_review' => true,
                'subscription_status' => 'pending',
            ],
        ]);

        return response()->json($payment->fresh(), 201);
    }

    public function manualRequests(Request $request)
    {
        $query = Payment::query()->with(['student:id,name,email,role', 'enrollment.course:id,title']);

        if ($request->user()->role === 'student') {
            $query->where('student_id', $request->user()->id);
        } elseif (in_array($request->user()->role, ['admin', 'finance'])) {
            $query->where('metadata->subscription_type', 'student_course');
        } elseif (in_array($request->user()->role, ['teacher', 'assistant'], true)) {
            $query->where('metadata->subscription_type', 'student_course');
        } else {
            abort(403);
        }

        $actor = $request->user();
        $scopedTeacherId = match ($actor->role) {
            'teacher' => (int) $actor->id,
            'assistant' => (int) ($actor->employer_teacher_id ?? 0),
            default => null,
        };

        return $query->where('provider', 'vodafone_cash')
            ->whereNotNull('metadata')
            ->latest()
            ->get()
            ->when($scopedTeacherId !== null && $scopedTeacherId > 0, function ($collection) use ($scopedTeacherId) {
                return $collection->filter(
                    fn (Payment $payment) => $this->paymentIncludesTeacher($payment, $scopedTeacherId)
                )->values();
            })
            ->map(function (Payment $payment) {
                $metadata = $payment->metadata ?? [];
                $receipt = $payment->receipt_path ?? ($metadata['receipt_path'] ?? null);
                if ($receipt) {
                    $metadata['receipt_path'] = str_starts_with($receipt, 'http')
                        ? $receipt
                        : url($receipt);
                    $metadata['receipt_type'] = str_ends_with(strtolower($receipt), '.pdf') ? 'pdf' : 'image';
                }
                $payment->metadata = $metadata;

                return $payment;
            });
    }

    public function updateManualStatus(Request $request, Payment $payment)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'finance', 'teacher', 'assistant']), 403);

        $subscriptionType = $payment->metadata['subscription_type'] ?? 'student_course';
        abort_unless($subscriptionType === 'student_course', 403, 'هذا الطلب ليس اشتراك طالب (فودافون كاش + إيصال).');

        $data = $request->validate([
            'status' => ['required', 'in:pending,paid,failed,refunded'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $metadata = $payment->metadata ?? [];
        $status = $data['status'];
        $user = $request->user();

        if (in_array($user->role, ['admin', 'finance'])) {
            $metadata['review_note'] = $data['note'] ?? null;
            $metadata['reviewed_by_role'] = $user->role;
            $metadata['reviewed_by_user_id'] = $user->id;
            $metadata['reviewed_at'] = now()->toDateTimeString();

            if ($status === 'paid') {
                foreach ($metadata['teacher_payouts'] ?? [] as $payout) {
                    if (!is_array($payout)) {
                        continue;
                    }
                    $metadata['teacher_approvals'][(string) ($payout['teacher_id'] ?? '')] = [
                        'status' => 'paid',
                        'note' => 'تأكيد من الإدارة',
                        'reviewed_at' => now()->toDateTimeString(),
                    ];
                }

                return response()->json($this->completePaidPayment($payment, $metadata));
            }

            $metadata['subscription_status'] = 'rejected';
            $metadata['approval_message'] = 'تم رفض الدفع. يرجى التحقق من المعلومات أو الإيصال.';
            $payment->update(['status' => $status, 'metadata' => $metadata]);
            $payment->load('student');
            $payment->student?->notify(new PaymentReviewedNotification($payment->fresh()));

            return response()->json($payment->fresh());
        }

        abort_unless($user->role === 'teacher', 403, 'مراجعة إيصال الطالب متاحة للمعلم صاحب المادة.');
        abort_unless($this->paymentIncludesTeacher($payment, $user->id), 403, 'هذا الطلب لا يخص موادك.');

        $approvals = $metadata['teacher_approvals'] ?? [];
        if ($status === 'paid' && ($approvals[(string) $user->id]['status'] ?? '') === 'paid') {
            abort(422, 'تم تأكيد إيصال هذا الطلب من قبل.');
        }
        $approvals[(string) $user->id] = [
            'status' => $status,
            'note' => $data['note'] ?? null,
            'reviewed_at' => now()->toDateTimeString(),
        ];
        $metadata['teacher_approvals'] = $approvals;

        if ($status === 'failed') {
            $metadata['subscription_status'] = 'rejected';
            $metadata['approval_message'] = 'رفض أحد المعلمين الإيصال. تواصل مع الدعم أو أعد رفع إيصال صحيح.';
            $payment->update(['status' => 'failed', 'metadata' => $metadata]);
            $payment->load('student');
            $payment->student?->notify(new PaymentReviewedNotification($payment->fresh()));

            return response()->json($payment->fresh());
        }

        if ($status === 'paid') {
            $durationDays = $this->subscriptionDurationDays($metadata);
            $courseIds = $this->courseIdsForTeacher($metadata, $user->id);
            abort_if(empty($courseIds), 422, 'لا توجد مواد مرتبطة بك في هذا الطلب.');

            $this->activateSelectedCourses($payment, $durationDays, $courseIds);
            User::query()->where('id', $payment->student_id)->update(['account_status' => 'active']);

            if ($this->allTeachersApproved($metadata)) {
                $metadata['subscription_status'] = 'active';
                $metadata['subscription_started_at'] = now()->toDateTimeString();
                $metadata['subscription_expires_at'] = now()->addDays($durationDays)->toDateTimeString();
                $metadata['approval_message'] = 'تم تأكيد الدفع من جميع المعلمين وتفعيل الاشتراك.';
                $payment->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'metadata' => $metadata,
                ]);
            } else {
                $metadata['subscription_status'] = 'partial_pending';
                $metadata['approval_message'] = 'تم تفعيل مواد هذا المعلم. في انتظار باقي المعلمين.';
                $payment->update(['status' => 'pending', 'metadata' => $metadata]);
            }

            $payment->load('student');
            $payment->student?->notify(new PaymentReviewedNotification($payment->fresh()));

            return response()->json($payment->fresh());
        }

        $payment->update(['metadata' => $metadata]);

        return response()->json($payment->fresh());
    }

    public function initiateOnline(Request $request, PaymobService $paymob)
    {
        abort_unless($request->user()->role === 'student', 403);

        $data = $request->validate([
            'plan_type' => ['required', 'string', 'in:semester,annual,chapter,unit'],
            'amount' => ['required', 'numeric', 'min:0'],
            'teacher_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $transactionId = 'online-'.time().'-'.$request->user()->id;
        $payment = Payment::create([
            'student_id' => $request->user()->id,
            'amount' => $data['amount'],
            'currency' => 'EGP',
            'provider' => $paymob->isConfigured() ? 'paymob' : 'sandbox',
            'transaction_id' => $transactionId,
            'status' => 'pending',
            'metadata' => [
                'plan_type' => $data['plan_type'],
                'teacher_id' => $data['teacher_id'] ?? null,
                'manual_review' => false,
                'online_checkout' => true,
                'subscription_status' => 'pending',
            ],
        ]);

        if ($paymob->isConfigured()) {
            $checkout = $paymob->createCheckout(
                (float) $data['amount'],
                [
                    'first_name' => $request->user()->name,
                    'email' => $request->user()->email ?? 'student@eltaisir.com',
                    'phone_number' => $request->user()->phone ?? '01000000000',
                ],
                $transactionId
            );

            $payment->update([
                'metadata' => array_merge($payment->metadata ?? [], [
                    'paymob_order_id' => $checkout['order_id'],
                    'paymob_payment_token' => $checkout['payment_token'],
                ]),
            ]);

            return response()->json([
                'payment' => $payment->fresh(),
                'checkout_url' => $checkout['checkout_url'],
                'provider' => 'paymob',
            ], 201);
        }

        $sandboxUrl = url('/api/v1/payments/sandbox/checkout/'.$payment->id);

        return response()->json([
            'payment' => $payment->fresh(),
            'checkout_url' => $sandboxUrl,
            'provider' => 'sandbox',
            'message' => 'وضع تجريبي: Paymob غير مُعدّ بعد.',
        ], 201);
    }

    public function show(Request $request, Payment $payment)
    {
        abort_unless(
            $request->user()->role !== 'student' || $payment->student_id === $request->user()->id,
            403
        );

        return $payment->load('student:id,name,email');
    }

    public function paymobCallback(Request $request, PaymobService $paymob)
    {
        if (!$paymob->verifyCallbackHmac($request->all())) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $obj = $request->input('obj', []);
        $success = filter_var($obj['success'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $merchantOrderId = $obj['order']['merchant_order_id'] ?? null;

        $payment = Payment::query()
            ->where('transaction_id', $merchantOrderId)
            ->orWhere('metadata->paymob_order_id', (string) ($obj['order']['id'] ?? ''))
            ->first();

        if (!$payment) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        if ($success) {
            $this->completePaidPayment($payment, $payment->metadata ?? []);
        } else {
            $payment->update(['status' => 'failed']);
        }

        return response()->json(['message' => 'Processed.']);
    }

    public function sandboxCheckout(Payment $payment)
    {
        abort_unless($payment->provider === 'sandbox' && $payment->status === 'pending', 404);

        $this->completePaidPayment($payment, $payment->metadata ?? []);

        return response()->view('payments.sandbox-success', [
            'payment' => $payment->fresh(),
        ]);
    }

    private function completePaidPayment(Payment $payment, array $metadata): Payment
    {
        $planType = $metadata['plan_type'] ?? 'semester';
        $durationDays = [
            'semester' => 120,
            'annual' => 365,
            'chapter' => 45,
            'unit' => 30,
        ][$planType] ?? 30;

        $metadata['subscription_status'] = 'active';
        $metadata['subscription_started_at'] = now()->toDateTimeString();
        $metadata['subscription_expires_at'] = now()->addDays($durationDays)->toDateTimeString();
        $metadata['approval_message'] = 'تم تأكيد الدفع وتفعيل الاشتراك بنجاح.';

        $subscriptionType = $metadata['subscription_type'] ?? 'student_course';
        if ($subscriptionType === 'teacher_platform') {
            User::query()
                ->where('id', $payment->student_id)
                ->update(['platform_expires_at' => now()->addDays($durationDays)]);
        } else {
            $courseIds = $metadata['course_ids'] ?? [];
            if (is_array($courseIds) && count($courseIds) > 0) {
                $this->activateSelectedCourses($payment, $durationDays, $courseIds);
            } else {
                $this->activateSubscriptionEnrollments($payment, $durationDays, $metadata['teacher_id'] ?? null);
            }

            User::query()->where('id', $payment->student_id)->update(['account_status' => 'active']);
        }

        $payment->update([
            'status' => 'paid',
            'paid_at' => now(),
            'metadata' => $metadata,
        ]);

        $payment->load('student');
        $payment->student?->notify(new PaymentReviewedNotification($payment->fresh()));

        return $payment->fresh();
    }

    private function subscriptionDurationDays(array $metadata): int
    {
        $planType = $metadata['plan_type'] ?? 'semester';

        return [
            'semester' => 120,
            'term' => 120,
            'annual' => 365,
            'year' => 365,
            'chapter' => 45,
            'unit' => 30,
            'custom' => 120,
        ][$planType] ?? 120;
    }

    private function buildCheckoutFromSubscriptionItems(array $items): array
    {
        $courses = collect();
        $selections = [];
        $total = 0.0;

        foreach ($items as $item) {
            $teacher = User::query()
                ->where('id', $item['teacher_id'])
                ->where('role', 'teacher')
                ->first();
            abort_unless($teacher, 422, 'معلّم غير صالح.');
            abort_if((int) $teacher->subject_id !== (int) $item['subject_id'], 422, 'المعلّم لا يدرّس هذه المادة.');

            $plan = TeacherSubscriptionPlan::query()
                ->where('teacher_id', $teacher->id)
                ->where('plan_type', $item['plan_type'])
                ->where('is_active', true)
                ->first();
            abort_unless($plan, 422, 'المعلّم لم يحدّد سعر هذا النوع من الاشتراك بعد.');

            $course = Course::query()
                ->where('teacher_id', $teacher->id)
                ->where('subject_id', $item['subject_id'])
                ->where('status', 'published')
                ->first();
            abort_unless($course, 422, 'محتوى المعلّم غير جاهز بعد. تواصل مع الإدارة.');

            $courses->push($course->load('teacher:id,name'));
            $price = (float) $plan->price;
            $total += $price;
            $selections[] = [
                'subject_id' => (int) $item['subject_id'],
                'teacher_id' => (int) $teacher->id,
                'teacher_name' => $teacher->name,
                'plan_type' => $plan->plan_type,
                'plan_label' => $plan->label,
                'price' => $price,
                'duration_days' => (int) $plan->duration_days,
                'course_id' => $course->id,
            ];
        }

        $teacherPayouts = collect($selections)
            ->groupBy('teacher_id')
            ->map(function ($group, $teacherId) {
                $first = $group->first();

                return [
                    'teacher_id' => (int) $teacherId,
                    'teacher_name' => $first['teacher_name'] ?? '',
                    'amount' => round($group->sum('price'), 2),
                    'course_ids' => $group->pluck('course_id')->unique()->values()->all(),
                    'duration_days' => (int) ($first['duration_days'] ?? 120),
                ];
            })
            ->values()
            ->all();

        return [
            'courses' => $courses->unique('id')->values(),
            'total' => round($total, 2),
            'teacher_payouts' => $teacherPayouts,
            'selections' => $selections,
        ];
    }

    private function paymentIncludesTeacher(Payment $payment, int $teacherId): bool
    {
        $metadata = $payment->metadata ?? [];
        $ids = $metadata['teacher_ids'] ?? [];
        if (in_array($teacherId, $ids, true)) {
            return true;
        }

        foreach ($ids as $id) {
            if ((int) $id === $teacherId) {
                return true;
            }
        }

        if ((int) ($metadata['teacher_id'] ?? 0) === $teacherId) {
            return true;
        }

        foreach ($metadata['teacher_payouts'] ?? [] as $payout) {
            if (is_array($payout) && (int) ($payout['teacher_id'] ?? 0) === $teacherId) {
                return true;
            }
        }

        foreach ($metadata['courses'] ?? [] as $course) {
            if (is_array($course) && (int) ($course['teacher_id'] ?? 0) === $teacherId) {
                return true;
            }
        }

        foreach ($metadata['subscription_items'] ?? [] as $item) {
            if (is_array($item) && (int) ($item['teacher_id'] ?? 0) === $teacherId) {
                return true;
            }
        }

        return false;
    }

    private function courseIdsForTeacher(array $metadata, int $teacherId): array
    {
        foreach ($metadata['teacher_payouts'] ?? [] as $payout) {
            if (!is_array($payout)) {
                continue;
            }
            if ((int) ($payout['teacher_id'] ?? 0) === $teacherId) {
                return $payout['course_ids'] ?? [];
            }
        }

        return [];
    }

    private function allTeachersApproved(array $metadata): bool
    {
        $payouts = $metadata['teacher_payouts'] ?? [];
        if (!is_array($payouts) || count($payouts) === 0) {
            return true;
        }

        $approvals = $metadata['teacher_approvals'] ?? [];
        foreach ($payouts as $payout) {
            if (!is_array($payout)) {
                continue;
            }
            $key = (string) ($payout['teacher_id'] ?? '');
            if (($approvals[$key]['status'] ?? '') !== 'paid') {
                return false;
            }
        }

        return true;
    }

    private function activateSelectedCourses(Payment $payment, int $durationDays, array $courseIds): void
    {
        $courses = Course::query()->whereIn('id', $courseIds)->where('status', 'published')->get();
        $courseMeta = collect(($payment->metadata['courses'] ?? []))->keyBy('id');
        $firstEnrollmentId = null;

        foreach ($courses as $course) {
            $days = (int) ($courseMeta[$course->id]['duration_days'] ?? $durationDays);
            $enrollment = Enrollment::updateOrCreate(
                ['student_id' => $payment->student_id, 'course_id' => $course->id],
                [
                    'status' => 'active',
                    'starts_at' => now(),
                    'expires_at' => now()->addDays($days),
                ]
            );
            $firstEnrollmentId ??= $enrollment->id;
        }

        if ($firstEnrollmentId && !$payment->enrollment_id) {
            $payment->update(['enrollment_id' => $firstEnrollmentId]);
        }
    }

    private function activateSubscriptionEnrollments(Payment $payment, int $durationDays, ?int $teacherId): void
    {
        $courses = Course::query()
            ->where('status', 'published')
            ->when($teacherId, fn ($q) => $q->where('teacher_id', $teacherId))
            ->get();

        $firstEnrollmentId = null;
        foreach ($courses as $course) {
            $enrollment = Enrollment::updateOrCreate(
                ['student_id' => $payment->student_id, 'course_id' => $course->id],
                [
                    'status' => 'active',
                    'starts_at' => now(),
                    'expires_at' => now()->addDays($durationDays),
                ]
            );
            $firstEnrollmentId ??= $enrollment->id;
        }

        if ($firstEnrollmentId && !$payment->enrollment_id) {
            $payment->update(['enrollment_id' => $firstEnrollmentId]);
        }
    }

    public function updateStatus(Request $request, Payment $payment)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'finance']), 403);
        $data = $request->validate([
            'status' => ['required', 'in:pending,paid,failed,refunded'],
            'transaction_id' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);
        if ($data['status'] === 'paid') {
            $data['paid_at'] = now();
        }
        $payment->update($data);

        return $payment->fresh();
    }

    public function streamReceipt(Request $request, Payment $payment): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['admin', 'finance', 'teacher', 'assistant', 'student'], true), 403);

        if ($user->role === 'student') {
            abort_unless((int) $payment->student_id === (int) $user->id, 403);
        } elseif ($user->role === 'teacher') {
            abort_unless($this->paymentIncludesTeacher($payment, (int) $user->id), 403);
        } elseif ($user->role === 'assistant') {
            $teacherId = (int) ($user->employer_teacher_id ?? 0);
            abort_unless($teacherId > 0 && $this->paymentIncludesTeacher($payment, $teacherId), 403);
        }

        $absolute = $this->resolveReceiptAbsolutePath($payment);
        abort_unless($absolute !== null && is_file($absolute), 404, 'ملف الإيصال غير موجود على الخادم.');

        $mime = mime_content_type($absolute) ?: 'application/octet-stream';

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function resolveReceiptAbsolutePath(Payment $payment): ?string
    {
        $metadata = $payment->metadata ?? [];
        $candidates = [
            $metadata['receipt_storage_path'] ?? null,
            $payment->receipt_path,
            $metadata['receipt_path'] ?? null,
        ];

        foreach ($candidates as $raw) {
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }
            $raw = trim($raw);
            if (str_contains($raw, '/storage/')) {
                $relative = Str::after($raw, '/storage/');

                return storage_path('app/public/'.ltrim($relative, '/'));
            }
            if (! str_starts_with($raw, 'http')) {
                return storage_path('app/public/'.ltrim($raw, '/'));
            }
        }

        return null;
    }
}