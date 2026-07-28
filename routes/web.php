<?php

declare(strict_types=1);

use App\Domains\Identity\Http\Controllers\PlexAuthorizationController;
use App\Domains\Identity\Http\Controllers\PlexCallbackController;
use App\Domains\Identity\Http\Controllers\RegisterController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome'))->middleware('auth')->name('home');

Route::middleware('guest')->group(function (): void {
    Route::post('/auth/plex', PlexAuthorizationController::class)->name('auth.plex.start');
    Route::get('/auth/plex/callback', PlexCallbackController::class)->name('auth.plex.callback');
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);
});
