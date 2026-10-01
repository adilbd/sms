<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PromotionController;
use App\Http\Controllers\Api\StudentController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Public content for the mobile app (no auth). Same data source as the Blade site.
Route::prefix('public')->middleware('throttle:60,1')->group(function () {
    Route::get('school', [\App\Http\Controllers\Api\PublicContentController::class, 'school']);
    Route::get('news', [\App\Http\Controllers\Api\PublicContentController::class, 'news']);
    Route::get('news/{slug}', [\App\Http\Controllers\Api\PublicContentController::class, 'newsShow']);
    Route::get('events', [\App\Http\Controllers\Api\PublicContentController::class, 'events']);
    Route::get('events/{slug}', [\App\Http\Controllers\Api\PublicContentController::class, 'eventsShow']);
    Route::get('galleries', [\App\Http\Controllers\Api\PublicContentController::class, 'galleries']);
    Route::get('galleries/{slug}', [\App\Http\Controllers\Api\PublicContentController::class, 'galleriesShow']);
    Route::get('staff', [\App\Http\Controllers\Api\PublicContentController::class, 'staff']);
    Route::get('staff/{staff}', [\App\Http\Controllers\Api\PublicContentController::class, 'staffShow'])->where('staff', '[0-9]+');
    Route::get('exams', [\App\Http\Controllers\Api\PublicContentController::class, 'exams']);
    Route::post('results', [\App\Http\Controllers\Api\PublicContentController::class, 'results'])->middleware('throttle:result-lookup');
    Route::post('contact', [\App\Http\Controllers\Api\PublicContentController::class, 'contact'])->middleware('throttle:5,1');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // Students. The enrolments route is registered before the resource (see the
    // class-teacher routes below).
    Route::get('students/{student}/enrolments', [StudentController::class, 'enrolments'])
        ->where('student', '[0-9]+');
    Route::apiResource('students', StudentController::class)
        ->where(['student' => '[0-9]+']);

    // Promotion of a section to the next academic year (edit-students).
    Route::get('promotions/preview', [PromotionController::class, 'preview']);
    Route::post('promotions', [PromotionController::class, 'store']);

    // Own records for the student and guardian roles (no broad students permission).
    Route::get('my/student', [\App\Http\Controllers\Api\MyRecordsController::class, 'student'])
        ->middleware('role:student');
    Route::get('my/children', [\App\Http\Controllers\Api\MyRecordsController::class, 'children'])
        ->middleware('role:parent');
    // Published results and the exam schedule of the caller's own class (or their children's).
    Route::get('my/results', [\App\Http\Controllers\Api\MyRecordsController::class, 'results'])
        ->middleware('role:student');
    Route::get('my/children/{student}/results', [\App\Http\Controllers\Api\MyRecordsController::class, 'childResults'])
        ->where('student', '[0-9]+')
        ->middleware('role:parent');
    // The caller's own attendance month (and a guardian's children's), student/parent roles
    // having no broad view-attendance permission.
    Route::get('my/attendance', [\App\Http\Controllers\Api\MyRecordsController::class, 'attendance'])
        ->middleware('role:student');
    Route::get('my/children/{student}/attendance', [\App\Http\Controllers\Api\MyRecordsController::class, 'childAttendance'])
        ->where('student', '[0-9]+')
        ->middleware('role:parent');
    // The signed-in teacher's own subjects and class-teacher sections.
    Route::get('my/assignments', [\App\Http\Controllers\Api\MyRecordsController::class, 'assignments'])
        ->middleware('role:teacher');
    Route::get('my/exams', [\App\Http\Controllers\Api\MyRecordsController::class, 'exams'])
        ->middleware('role:student|parent');

    // Classes. The curriculum routes are registered before the resource, like the
    // class-teacher routes below.
    Route::get('classes/{class}/subjects', [\App\Http\Controllers\Api\ClassController::class, 'curriculum'])
        ->where('class', '[0-9]+');
    Route::put('classes/{class}/subjects', [\App\Http\Controllers\Api\ClassController::class, 'updateCurriculum'])
        ->where('class', '[0-9]+');
    Route::apiResource('classes', \App\Http\Controllers\Api\ClassController::class)
        ->where(['class' => '[0-9]+']);

    // Sections. The class-teacher routes are registered before the resource so
    // 'class-teachers'/'class-teacher' aren't captured by the {section} wildcard.
    Route::get('sections/{section}/class-teachers', [\App\Http\Controllers\Api\SectionController::class, 'classTeachers'])
        ->where('section', '[0-9]+');
    Route::put('sections/{section}/class-teacher', [\App\Http\Controllers\Api\SectionController::class, 'updateClassTeacher'])
        ->where('section', '[0-9]+');
    Route::put('sections/{section}/subject-teachers', [\App\Http\Controllers\Api\SectionController::class, 'updateSubjectTeachers'])
        ->where('section', '[0-9]+');
    Route::apiResource('sections', \App\Http\Controllers\Api\SectionController::class)
        ->where(['section' => '[0-9]+']);

    // Subjects
    Route::apiResource('subjects', \App\Http\Controllers\Api\SubjectController::class)
        ->where(['subject' => '[0-9]+']);

    // Subject teachers: who teaches each subject in each section per academic year.
    // The bulk form is PUT sections/{section}/subject-teachers above.
    Route::apiResource('subject-assignments', \App\Http\Controllers\Api\SubjectAssignmentController::class)
        ->where(['subject_assignment' => '[0-9]+']);

    // Staff (teachers and non-teaching staff). Replaces the old teachers stub.
    Route::apiResource('staff', \App\Http\Controllers\Api\StaffController::class)
        ->where(['staff' => '[0-9]+']);

    // Shifts
    Route::apiResource('shifts', \App\Http\Controllers\Api\ShiftController::class)
        ->where(['shift' => '[0-9]+']);

    // Academic Years
    Route::apiResource('academic-years', \App\Http\Controllers\Api\AcademicYearController::class)
        ->where(['academic_year' => '[0-9]+']);
    Route::post('academic-years/{academicYear}/activate', [\App\Http\Controllers\Api\AcademicYearController::class, 'activate'])
        ->where('academicYear', '[0-9]+');

    // Attendance: one sheet per section and day, saved by the class teacher or an admin.
    Route::get('attendance/sheet', [\App\Http\Controllers\Api\AttendanceController::class, 'sheet']);
    Route::put('attendance/sheet', [\App\Http\Controllers\Api\AttendanceController::class, 'saveSheet']);
    Route::get('attendance/report', [\App\Http\Controllers\Api\AttendanceController::class, 'report']);
    Route::get('attendance/students/{student}', [\App\Http\Controllers\Api\AttendanceController::class, 'student'])
        ->where('student', '[0-9]+');

    // Listed school holidays (weekly holidays are an institute setting).
    Route::apiResource('holidays', \App\Http\Controllers\Api\HolidayController::class)
        ->where(['holiday' => '[0-9]+']);

    // Exams. The extra actions have more path segments than the resource routes, so they
    // can't be captured by its {exam} wildcard.
    Route::post('exams/{exam}/open-marks-entry', [\App\Http\Controllers\Api\ExamController::class, 'openMarksEntry'])
        ->where('exam', '[0-9]+');
    Route::post('exams/{exam}/classes/{class}/regenerate', [\App\Http\Controllers\Api\ExamController::class, 'regenerateClass'])
        ->where(['exam' => '[0-9]+', 'class' => '[0-9]+']);
    Route::get('exams/{exam}/subjects', [\App\Http\Controllers\Api\ExamSubjectController::class, 'index'])
        ->where('exam', '[0-9]+');
    Route::put('exams/{exam}/subjects/{examSubject}', [\App\Http\Controllers\Api\ExamSubjectController::class, 'update'])
        ->where(['exam' => '[0-9]+', 'examSubject' => '[0-9]+']);
    Route::get('exams/{exam}/marks', [\App\Http\Controllers\Api\ExamMarkController::class, 'sheet'])
        ->where('exam', '[0-9]+');
    Route::put('exams/{exam}/marks', [\App\Http\Controllers\Api\ExamMarkController::class, 'save'])
        ->where('exam', '[0-9]+');
    Route::post('exams/{exam}/process', [\App\Http\Controllers\Api\ExamResultController::class, 'process'])
        ->where('exam', '[0-9]+');
    Route::post('exams/{exam}/publish', [\App\Http\Controllers\Api\ExamResultController::class, 'publish'])
        ->where('exam', '[0-9]+');
    Route::post('exams/{exam}/unpublish', [\App\Http\Controllers\Api\ExamResultController::class, 'unpublish'])
        ->where('exam', '[0-9]+');
    Route::post('exams/{exam}/reopen', [\App\Http\Controllers\Api\ExamResultController::class, 'reopen'])
        ->where('exam', '[0-9]+');
    Route::get('exams/{exam}/results', [\App\Http\Controllers\Api\ExamResultController::class, 'index'])
        ->where('exam', '[0-9]+');
    Route::get('exams/{exam}/results/{student}', [\App\Http\Controllers\Api\ExamResultController::class, 'show'])
        ->where(['exam' => '[0-9]+', 'student' => '[0-9]+']);
    Route::apiResource('exams', \App\Http\Controllers\Api\ExamController::class)
        ->where(['exam' => '[0-9]+']);

    // Fee Types
    Route::apiResource('fee-types', \App\Http\Controllers\Api\FeeTypeController::class);

    // Fee Structures
    Route::apiResource('fee-structures', \App\Http\Controllers\Api\FeeStructureController::class);

    // Fee Payments
    Route::apiResource('fee-payments', \App\Http\Controllers\Api\FeePaymentController::class);
    Route::get('fee-payments/student/{student}', [\App\Http\Controllers\Api\FeePaymentController::class, 'studentPayments']);
    Route::get('fee-payments/receipt/{feePayment}', [\App\Http\Controllers\Api\FeePaymentController::class, 'generateReceipt']);

    // Public website content (news & events)
    Route::post('posts/media', [\App\Http\Controllers\Api\PostMediaController::class, 'store']);
    Route::apiResource('posts', \App\Http\Controllers\Api\PostController::class)->where(['post' => '[0-9]+']);

    // Standalone public content pages
    Route::apiResource('pages', \App\Http\Controllers\Api\PageController::class)->where(['page' => '[0-9]+']);

    // Reusable media library images, picked into galleries.
    Route::apiResource('media', \App\Http\Controllers\Api\MediaController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['media' => 'media'])
        ->where(['media' => '[0-9]+']);

    // Photo and video galleries. Items are managed through the gallery's own
    // store/update payload; there is no standalone gallery-item endpoint.
    Route::apiResource('galleries', \App\Http\Controllers\Api\GalleryController::class)
        ->where(['gallery' => '[0-9]+']);

    // Header navigation menu. The reorder route is registered before the resource
    // so 'reorder' isn't captured by the {menu_item} wildcard.
    Route::put('menu-items/reorder', [\App\Http\Controllers\Api\MenuItemController::class, 'reorder']);
    Route::apiResource('menu-items', \App\Http\Controllers\Api\MenuItemController::class)
        ->where(['menu_item' => '[0-9]+']);

    // Institute settings (name, logo, contact, address...). The SPA sends a
    // multipart POST with _method=PUT so the file upload survives.
    Route::get('settings/institute', [\App\Http\Controllers\Api\InstituteSettingsController::class, 'show']);
    Route::put('settings/institute', [\App\Http\Controllers\Api\InstituteSettingsController::class, 'update']);

    // Dashboard
    Route::get('dashboard/stats', [\App\Http\Controllers\Api\DashboardController::class, 'stats']);
    Route::get('dashboard/recent-activities', [\App\Http\Controllers\Api\DashboardController::class, 'recentActivities']);
});
