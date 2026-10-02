<?php

use App\Http\Controllers\Web\AdmissionController;
use App\Http\Controllers\Web\ContactController;
use App\Http\Controllers\Web\GalleryController;
use App\Http\Controllers\Web\PageController;
use App\Http\Controllers\Web\PortalAuthController;
use App\Http\Controllers\Web\PortalController;
use App\Http\Controllers\Web\PostController;
use App\Http\Controllers\Web\PublicController;
use App\Http\Controllers\Web\ResultController;
use App\Http\Controllers\Web\SeoController;
use App\Http\Controllers\Web\StaffController;
use Illuminate\Support\Facades\Route;

// Public, server-rendered, SEO-friendly pages
Route::get('/', [PublicController::class, 'home'])->name('home');
Route::get('/about', [PublicController::class, 'about'])->name('about');

// Online admission. The form is indexable while its round is open; the confirmation and
// the status lookup are private to the family (noindex, no-store), and the lookup is a
// POST so the date of birth never reaches a URL.
Route::get('/admissions', [AdmissionController::class, 'index'])->name('admissions');
Route::get('/admissions/apply/{round}', [AdmissionController::class, 'apply'])->where('round', '[0-9]+')->name('admissions.apply');
Route::post('/admissions/apply/{round}', [AdmissionController::class, 'submit'])->where('round', '[0-9]+')->middleware('throttle:admission-apply')->name('admissions.apply.store');
Route::middleware(\App\Http\Middleware\PortalHeaders::class)->group(function () {
    Route::get('/admissions/submitted', [AdmissionController::class, 'submitted'])->name('admissions.submitted');
    Route::get('/admissions/status', [AdmissionController::class, 'statusForm'])->name('admissions.status');
    Route::post('/admissions/status', [AdmissionController::class, 'statusShow'])->middleware('throttle:admission-status')->name('admissions.status.show');
});

Route::get('/contact', [ContactController::class, 'show'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:5,1')->name('contact.store');

Route::get('/news', [PostController::class, 'newsIndex'])->name('news.index');
Route::get('/news/{slug}', [PostController::class, 'newsShow'])->name('news.show');
Route::get('/events', [PostController::class, 'eventsIndex'])->name('events.index');
Route::get('/events/{slug}', [PostController::class, 'eventsShow'])->name('events.show');

Route::get('/gallery', [GalleryController::class, 'index'])->name('gallery.index');
Route::get('/gallery/{slug}', [GalleryController::class, 'show'])->name('gallery.show');

// Named "page.show" (singular), not "pages.show", so it doesn't collide with the
// apiResource('pages', ...) route of the same name registered in routes/api.php.
Route::get('/pages/{slug}', [PageController::class, 'show'])->name('page.show');

// স্কুল প্রশাসন (School Administration): current and former head/assistant head/
// teachers/staff, plus a profile page. See docs/tasks/staff-module.md.
Route::get('/administration/head', [StaffController::class, 'head'])->name('staff.head');
Route::get('/administration/assistant-head', [StaffController::class, 'assistantHead'])->name('staff.assistant_head');
Route::get('/administration/teachers', [StaffController::class, 'teachers'])->name('staff.teachers');
Route::get('/administration/staff', [StaffController::class, 'employees'])->name('staff.employees');
Route::get('/administration/ex-heads', [StaffController::class, 'exHeads'])->name('staff.ex_heads');
Route::get('/administration/ex-teachers', [StaffController::class, 'exTeachers'])->name('staff.ex_teachers');
Route::get('/administration/ex-staff', [StaffController::class, 'exEmployees'])->name('staff.ex_employees');
Route::get('/administration/staff/{staff}', [StaffController::class, 'show'])->name('staff.show')->where('staff', '[0-9]+');

// Public result lookup. The marksheet is a POST so the date of birth never reaches a URL.
Route::get('/results', [ResultController::class, 'index'])->name('results.index');
Route::get('/results/archive', [ResultController::class, 'archive'])->name('results.archive');
Route::post('/results', [ResultController::class, 'show'])->middleware('throttle:result-lookup')->name('results.show');

// Student and guardian portal: session (web guard) login, private and never indexed.
// The login pages get the private headers only; everything else sits behind the `portal`
// middleware group (private headers plus the student/parent session gate).
Route::prefix('portal')->name('portal.')->group(function () {
    Route::middleware(\App\Http\Middleware\PortalHeaders::class)->group(function () {
        Route::get('/login', [PortalAuthController::class, 'show'])->name('login');
        Route::post('/login', [PortalAuthController::class, 'login'])->middleware('throttle:login')->name('login.store');
        // Any web session may sign out (a staff one included); still CSRF-protected.
        Route::post('/logout', [PortalAuthController::class, 'logout'])->name('logout');
    });

    Route::middleware('portal')->group(function () {
        Route::get('/', [PortalController::class, 'dashboard'])->name('dashboard');
        Route::get('/profile', [PortalController::class, 'profile'])->name('profile');
        Route::put('/profile/password', [PortalController::class, 'changePassword'])->name('password');
        Route::get('/results', [PortalController::class, 'results'])->name('results');
        Route::get('/results/{exam}', [PortalController::class, 'result'])->where('exam', '[0-9]+')->name('result');
        Route::get('/attendance', [PortalController::class, 'attendance'])->name('attendance');
        Route::get('/fees', [PortalController::class, 'fees'])->name('fees');
        Route::get('/fees/receipts/{payment}', [PortalController::class, 'receipt'])->where('payment', '[0-9]+')->name('receipt');
        Route::get('/exams', [PortalController::class, 'exams'])->name('exams');
    });
});

Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');

// Admin SPA (Vue). Client-side routing handles everything under /admin.
Route::view('/admin/{any?}', 'admin')->where('any', '.*')->name('admin');
