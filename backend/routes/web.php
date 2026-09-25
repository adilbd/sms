<?php

use App\Http\Controllers\Web\ContactController;
use App\Http\Controllers\Web\PostController;
use App\Http\Controllers\Web\PublicController;
use App\Http\Controllers\Web\SeoController;
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

Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');

// Admin SPA (Vue). Client-side routing handles everything under /admin.
Route::view('/admin/{any?}', 'admin')->where('any', '.*')->name('admin');
