<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::post('olvide-mi-contrasena', [PasswordResetLinkController::class, 'store'])
        ->name('contrasena.enviar-link');

    Route::get('resetear-contrasena/{token}', [NewPasswordController::class, 'create'])
        ->name('contrasena.resetear');

    Route::post('resetear-contrasena', [NewPasswordController::class, 'store'])
        ->name('contrasena.actualizar');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
