<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StudentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

// Public content for the mobile app (no auth). Same data source as the Blade site.
Route::prefix('public')->middleware('throttle:60,1')->group(function () {
    Route::get('school', [\App\Http\Controllers\Api\PublicContentController::class, 'school']);
    Route::get('news', [\App\Http\Controllers\Api\PublicContentController::class, 'news']);
    Route::get('news/{slug}', [\App\Http\Controllers\Api\PublicContentController::class, 'newsShow']);
    Route::get('events', [\App\Http\Controllers\Api\PublicContentController::class, 'events']);
    Route::get('events/{slug}', [\App\Http\Controllers\Api\PublicContentController::class, 'eventsShow']);
    Route::post('contact', [\App\Http\Controllers\Api\PublicContentController::class, 'contact'])->middleware('throttle:5,1');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // Students
    Route::apiResource('students', StudentController::class);

    // Classes
    Route::apiResource('classes', \App\Http\Controllers\Api\ClassController::class);

    // Sections
    Route::apiResource('sections', \App\Http\Controllers\Api\SectionController::class);

    // Subjects
    Route::apiResource('subjects', \App\Http\Controllers\Api\SubjectController::class);

    // Teachers
    Route::apiResource('teachers', \App\Http\Controllers\Api\TeacherController::class);

    // Parents
    Route::apiResource('parents', \App\Http\Controllers\Api\ParentController::class);

    // Academic Years
    Route::apiResource('academic-years', \App\Http\Controllers\Api\AcademicYearController::class);
    Route::post('academic-years/{academicYear}/activate', [\App\Http\Controllers\Api\AcademicYearController::class, 'activate']);

    // Attendance
    Route::apiResource('attendances', \App\Http\Controllers\Api\AttendanceController::class);
    Route::post('attendances/bulk', [\App\Http\Controllers\Api\AttendanceController::class, 'bulkStore']);
    Route::get('attendances/report/{student}', [\App\Http\Controllers\Api\AttendanceController::class, 'studentReport']);

    // Exams
    Route::apiResource('exams', \App\Http\Controllers\Api\ExamController::class);
    Route::post('exams/{exam}/publish', [\App\Http\Controllers\Api\ExamController::class, 'publish']);

    // Exam Schedules
    Route::apiResource('exam-schedules', \App\Http\Controllers\Api\ExamScheduleController::class);

    // Exam Results
    Route::apiResource('exam-results', \App\Http\Controllers\Api\ExamResultController::class);
    Route::get('exam-results/student/{student}/exam/{exam}', [\App\Http\Controllers\Api\ExamResultController::class, 'studentExamResults']);

    // Fee Types
    Route::apiResource('fee-types', \App\Http\Controllers\Api\FeeTypeController::class);

    // Fee Structures
    Route::apiResource('fee-structures', \App\Http\Controllers\Api\FeeStructureController::class);

    // Fee Payments
    Route::apiResource('fee-payments', \App\Http\Controllers\Api\FeePaymentController::class);
    Route::get('fee-payments/student/{student}', [\App\Http\Controllers\Api\FeePaymentController::class, 'studentPayments']);
    Route::get('fee-payments/receipt/{feePayment}', [\App\Http\Controllers\Api\FeePaymentController::class, 'generateReceipt']);

    // Public website content (news & events)
    Route::apiResource('posts', \App\Http\Controllers\Api\PostController::class)->middleware('role:admin');

    // Dashboard
    Route::get('dashboard/stats', [\App\Http\Controllers\Api\DashboardController::class, 'stats']);
    Route::get('dashboard/recent-activities', [\App\Http\Controllers\Api\DashboardController::class, 'recentActivities']);
});

