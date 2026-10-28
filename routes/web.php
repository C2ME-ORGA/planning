<?php

use App\Http\Controllers\Auth\AccountActivationController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Livewire\Planning\MobilePlanning;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Point d'entrée unique pour les redirections `auth` de Laravel : le panneau
// Filament reste la seule page de connexion existante à ce lot.
Route::get('/login', static fn () => redirect()->route('filament.admin.auth.login'))->name('login');

Route::middleware(['guest', 'throttle:10,1'])->group(function (): void {
    Route::get('/activation/{token}', [AccountActivationController::class, 'show'])
        ->name('account.activation.show');

    Route::post('/activation/{token}', [AccountActivationController::class, 'store'])
        ->name('account.activation.store');
});

Route::middleware(['auth', EnsureAccountIsActive::class])->group(function (): void {
    Route::get('/planning', MobilePlanning::class)->name('planning.mobile');
});
