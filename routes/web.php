<?php

use App\Http\Controllers\Auth\MicrosoftLoginController;
use Illuminate\Support\Facades\Route;

// The Filament panel lives at "/". These are the only routes outside of it.

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [MicrosoftLoginController::class, 'show'])->name('login');
    Route::get('/auth/microsoft', [MicrosoftLoginController::class, 'redirect'])
        ->middleware('throttle:20,1')
        ->name('auth.microsoft.redirect');
    Route::get('/auth/microsoft/callback', [MicrosoftLoginController::class, 'callback'])
        ->middleware('throttle:20,1')
        ->name('auth.microsoft.callback');
});
