<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ScanController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('scanner'))->name('home');

Route::post('/api/scan', [ScanController::class, 'scan'])
    ->middleware('scan.apikey')
    ->name('scan.api');

Route::post('/api/scan/export', [ScanController::class, 'export'])
    ->middleware('scan.apikey')
    ->name('scan.export');

// Riwayat scan milik user yang login (rekaman otomatis tiap POST /api/scan)
Route::get('/api/history', [ScanController::class, 'history'])
    ->middleware('auth')
    ->name('scan.history');

// ------------------------------------------------------------------
// Autentikasi (sesi)
// ------------------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// ------------------------------------------------------------------
// Proyek (wajib login)
// ------------------------------------------------------------------
Route::middleware('auth')->group(function () {
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    Route::post('/projects/{project}/scan', [ProjectController::class, 'scan'])->name('projects.scan');
    Route::post('/projects/{project}/scan/export', [ProjectController::class, 'scanExport'])->name('projects.scan.export');
});
