<?php

use App\Http\Controllers\Auth\MicrosoftLoginController;
use App\Http\Controllers\ClickUpConnectionController;
use App\Http\Controllers\MailContentController;
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

// Mail content (R12, R13). The policy is checked in the controller.
Route::middleware('auth')->group(function (): void {
    Route::get('/mail/{item}/original', [MailContentController::class, 'original'])
        ->whereNumber('item')
        ->name('intake.original');
    Route::get('/mail/attachments/{attachment}', [MailContentController::class, 'download'])
        ->whereNumber('attachment')
        ->name('intake.attachment');
});

// ClickUp OAuth per user (E2). The callback URL is entered in the ClickUp OAuth app.
Route::middleware(['auth', 'throttle:20,1'])->group(function (): void {
    Route::get('/clickup/connect', [ClickUpConnectionController::class, 'connect'])->name('clickup.connect');
    Route::get('/clickup/callback', [ClickUpConnectionController::class, 'callback'])->name('clickup.callback');
    Route::post('/clickup/disconnect', [ClickUpConnectionController::class, 'disconnect'])->name('clickup.disconnect');
});

// Inline images for the sandboxed iframe, which sends no session cookie: signed URLs only.
Route::get('/mail/{item}/inline/{attachment}', [MailContentController::class, 'inline'])
    ->whereNumber(['item', 'attachment'])
    ->middleware(['signed:relative', 'throttle:300,1'])
    ->scopeBindings()
    ->name('intake.inline');
