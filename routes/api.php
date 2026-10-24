<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CourseController;
use App\Http\Controllers\Api\V1\EnrollmentController;
use App\Http\Controllers\Api\V1\ExamController;
use App\Http\Controllers\Api\V1\AssignmentController;
use App\Http\Controllers\Api\V1\LessonController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\SubjectController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\ProgressController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\QuickLessonController;
use App\Http\Controllers\Api\V1\StatisticsController;
use App\Http\Controllers\Api\V1\ParentController;
use App\Http\Controllers\Api\V1\TeacherController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\GradeLevelController;
use App\Http\Controllers\Api\V1\LessonFeedbackController;
use App\Http\Controllers\Api\V1\LessonInsightsController;
use App\Http\Controllers\Api\V1\TeacherAssistantController;
use App\Http\Controllers\Api\V1\TeacherSubscriptionPlanController;
use App\Http\Controllers\Api\V1\TeacherEarningController;
use App\Http\Controllers\Api\V1\StudentAlertController;
use App\Http\Controllers\Api\V1\TeacherStudentController;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', function () {
        return response()->json([
            'name' => config('app.name'),
            'status' => 'ok',
            'version' => 'v1',
        ]);
    });

    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::get('/teachers', [TeacherController::class, 'index']);
    Route::get('/catalog/courses', [CatalogController::class, 'courses']);
    Route::get('/catalog/subjects', [CatalogController::class, 'subjects']);
    Route::get('/catalog/grade-levels', [CatalogController::class, 'gradeLevels']);
    Route::get('/catalog/subscription-offers', [CatalogController::class, 'subscriptionOffers']);
    Route::post('/quick-lessons/resolve', [QuickLessonController::class, 'resolve']);
    Route::post('/quick-lessons/{code}/complete', [QuickLessonController::class, 'complete']);
    Route::get('quick-lessons/{code}/video', [QuickLessonController::class, 'streamVideo']);
    Route::post('/payments/paymob/callback', [PaymentController::class, 'paymobCallback']);
    Route::get('/payments/sandbox/checkout/{payment}', [PaymentController::class, 'sandboxCheckout']);
    // Stream: auth via Bearer أو ?token= (مطلوب لتشغيل الفيدio على Flutter Web)
    Route::get('lessons/{lesson}/video', [LessonController::class, 'streamVideo']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', function (Request $request) {
            return response()->json($request->user());
        });

        Route::apiResource('subjects', SubjectController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('grade-levels', GradeLevelController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('courses', CourseController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
        Route::post('courses/{course}/term/end', [CourseController::class, 'endTerm']);
        Route::post('courses/{course}/term/review', [CourseController::class, 'configureReview']);
        Route::post('courses/{course}/term/next', [CourseController::class, 'startNextTerm']);
        Route::get('courses/{course}/term/progress', [CourseController::class, 'termProgress']);
        Route::get('teacher/earnings', [TeacherEarningController::class, 'index']);
        Route::get('courses/{course}/lessons', [LessonController::class, 'index']);
        Route::post('courses/{course}/lessons', [LessonController::class, 'store']);
        Route::put('lessons/{lesson}', [LessonController::class, 'update']);
        Route::delete('lessons/{lesson}', [LessonController::class, 'destroy']);
        Route::post('payments/teacher-platform', [PaymentController::class, 'createTeacherPlatformRequest']);
        Route::get('enrollments', [EnrollmentController::class, 'index']);
        Route::post('courses/{course}/enroll', [EnrollmentController::class, 'store']);
        Route::patch('enrollments/{enrollment}/status', [EnrollmentController::class, 'updateStatus']);
        Route::get('payment-plans', [PaymentController::class, 'plans']);
        Route::get('payments', [PaymentController::class, 'index']);
        Route::get('payments/manual', [PaymentController::class, 'manualRequests']);
        Route::post('payments/manual', [PaymentController::class, 'createManualRequest']);
        Route::post('payments/online', [PaymentController::class, 'initiateOnline']);
        Route::get('payments/{payment}', [PaymentController::class, 'show']);
        Route::get('payments/{payment}/receipt', [PaymentController::class, 'streamReceipt']);
        Route::patch('payments/{payment}/manual-status', [PaymentController::class, 'updateManualStatus']);
        Route::post('enrollments/{enrollment}/payments', [PaymentController::class, 'store']);
        Route::patch('payments/{payment}/status', [PaymentController::class, 'updateStatus']);
        Route::get('courses/{course}/exams', [ExamController::class, 'index']);
        Route::post('courses/{course}/exams', [ExamController::class, 'store']);
        Route::get('exams/{exam}', [ExamController::class, 'show']);
        Route::get('exams/{exam}/my-result', [ExamController::class, 'myLatestResult']);
        Route::post('exams/{exam}/questions', [ExamController::class, 'addQuestion']);
        Route::get('me/exam-attempts', [ExamController::class, 'myAttempts']);
        Route::get('teacher/exam-attempts', [ExamController::class, 'teacherAttempts']);
        Route::get('teacher/students', [TeacherController::class, 'students']);
        Route::post('quick-lessons', [QuickLessonController::class, 'create']);
        Route::get('teacher/subscription-plans', [TeacherSubscriptionPlanController::class, 'index']);
        Route::put('teacher/subscription-plans', [TeacherSubscriptionPlanController::class, 'upsert']);
        Route::get('me/stats', [ProgressController::class, 'myStats']);
        Route::get('me/alerts', [StudentAlertController::class, 'index']);
        Route::get('me/alerts/unread-count', [StudentAlertController::class, 'unreadCount']);
        Route::post('me/alerts/{alert}/read', [StudentAlertController::class, 'markRead']);
        Route::post('teacher/students/{student}/remove', [TeacherStudentController::class, 'removeFromPlatform']);
        Route::get('admin/student-removal-requests', [TeacherStudentController::class, 'removalRequestsForAdmin']);
        Route::get('teacher/assistants', [TeacherAssistantController::class, 'index']);
        Route::post('teacher/assistants', [TeacherAssistantController::class, 'store']);
        Route::delete('teacher/assistants/{user}', [TeacherAssistantController::class, 'destroy']);
        Route::post('exams/{exam}/attempts', [ExamController::class, 'submit']);
        Route::get('courses/{course}/assignments', [AssignmentController::class, 'index']);
        Route::get('lessons/{lesson}/assignment', [AssignmentController::class, 'forLesson']);
        Route::post('courses/{course}/assignments', [AssignmentController::class, 'store']);
        Route::post('assignments/{assignment}/submissions', [AssignmentController::class, 'submit']);
        Route::patch('assignment-submissions/{submission}/grade', [AssignmentController::class, 'grade']);
        Route::get('assignments/{assignment}/submissions', [AssignmentController::class, 'submissions']);
        Route::post('lessons/{lesson}/feedback', [LessonFeedbackController::class, 'store']);
        Route::get('lessons/{lesson}/feedback/mine', [LessonFeedbackController::class, 'mine']);
        Route::get('lessons/{lesson}/insights', [LessonInsightsController::class, 'show']);
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::put('users/{user}', [UserController::class, 'update']);
        Route::delete('users/{user}', [UserController::class, 'destroy']);
        Route::get('statistics', [StatisticsController::class, 'index']);
        Route::get('parent/children', [ParentController::class, 'children']);
        Route::post('parent/link-child', [ParentController::class, 'linkChild']);
        Route::get('parent/children/{student}/report', [ParentController::class, 'childReport']);
        Route::get('conversations', [ConversationController::class, 'index']);
        Route::post('conversations', [ConversationController::class, 'store']);
        Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages']);
        Route::post('conversations/{conversation}/messages', [ConversationController::class, 'send']);
        Route::post('lessons/{lesson}/progress', [ProgressController::class, 'updateLesson']);
        Route::get('students/{student}/report', [ProgressController::class, 'studentReport']);
        Route::get('attendance', 'App\\Http\\Controllers\\Api\\V1\\AttendanceController@index');
        Route::post('courses/{course}/attendance', 'App\\Http\\Controllers\\Api\\V1\\AttendanceController@store');
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read']);
    });
});