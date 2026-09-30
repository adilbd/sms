<?php

use App\Http\Controllers\Web\ContactController;
use App\Http\Controllers\Web\GalleryController;
use App\Http\Controllers\Web\PageController;
use App\Http\Controllers\Web\PostController;
use App\Http\Controllers\Web\PublicController;
use App\Http\Controllers\Web\SeoController;
use App\Http\Controllers\Web\StaffController;
use Illuminate\Support\Facades\Route;

// Public, server-rendered, SEO-friendly pages
Route::get('/', [PublicController::class, 'home'])->name('home');
Route::get('/about', [PublicController::class, 'about'])->name('about');
Route::get('/admissions', [PublicController::class, 'admissions'])->name('admissions');

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

Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');

// Admin SPA (Vue). Client-side routing handles everything under /admin.
Route::view('/admin/{any?}', 'admin')->where('any', '.*')->name('admin');
