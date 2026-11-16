<?php

use App\Modules\Access\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [SessionController::class, 'create'])->name('login');
Route::post('/login', [SessionController::class, 'store']);
Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');
Route::view('/dashboard', 'dashboard')->middleware(['auth', 'can:view-treasury'])->name('dashboard');
