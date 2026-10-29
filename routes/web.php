<?php

use App\Http\Controllers\Auth\AccountActivationController;
use App\Http\Controllers\Auth\TerrainLoginController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Livewire\Planning\MobilePlanning;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('/login', [TerrainLoginController::class, 'show'])->name('login');
Route::post('/login', [TerrainLoginController::class, 'store'])
    ->middleware(['guest', 'throttle:10,1'])
    ->name('login.store');
Route::post('/logout', [TerrainLoginController::class, 'destroy'])
    ->middleware(['auth', EnsureAccountIsActive::class])
    ->name('logout');

Route::middleware(['guest', 'throttle:10,1'])->group(function (): void {
    Route::get('/activation/{token}', [AccountActivationController::class, 'show'])
        ->name('account.activation.show');

    Route::post('/activation/{token}', [AccountActivationController::class, 'store'])
        ->name('account.activation.store');
});

Route::middleware(['auth', EnsureAccountIsActive::class])->group(function (): void {
    Route::get('/planning', MobilePlanning::class)->name('planning.mobile');
});
