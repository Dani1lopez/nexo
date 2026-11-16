<?php

use App\Modules\Access\Http\Controllers\SessionController;
use App\Modules\Bank\Http\Controllers\BankImportController;
use App\Modules\Fees\Http\Controllers\ExemptionController;
use App\Modules\Fees\Http\Controllers\FeeYearController;
use App\Modules\Fees\Http\Controllers\PaymentController;
use App\Modules\Members\Http\Controllers\MemberController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [SessionController::class, 'create'])->name('login');
Route::post('/login', [SessionController::class, 'store']);
Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');
Route::view('/dashboard', 'dashboard')->middleware(['auth', 'can:view-treasury'])->name('dashboard');

Route::middleware(['auth', 'can:view-treasury'])->prefix('members')->name('members.')->group(function (): void {
    Route::get('/', [MemberController::class, 'index'])->name('index');

    Route::middleware('can:edit-treasury')->group(function (): void {
        Route::get('/create', [MemberController::class, 'create'])->name('create');
        Route::post('/', [MemberController::class, 'store'])->name('store');
        Route::get('/{member}/edit', [MemberController::class, 'edit'])->name('edit');
        Route::put('/{member}', [MemberController::class, 'update'])->name('update');
        Route::post('/{member}/deactivate', [MemberController::class, 'deactivate'])->name('deactivate');
        Route::post('/{member}/reactivate', [MemberController::class, 'reactivate'])->name('reactivate');
    });
});

Route::middleware(['auth', 'can:view-treasury'])->group(function (): void {
    Route::get('/fees', [FeeYearController::class, 'index'])->name('fees.index');

    Route::middleware('can:edit-treasury')->group(function (): void {
        Route::get('/fees/create', [FeeYearController::class, 'create'])->name('fees.create');
        Route::post('/fees', [FeeYearController::class, 'store'])->name('fees.store');
        Route::get('/fees/{feeYear}/edit', [FeeYearController::class, 'edit'])->name('fees.edit');
        Route::put('/fees/{feeYear}', [FeeYearController::class, 'update'])->name('fees.update');
        Route::post('/dues/{due}/exemptions', [ExemptionController::class, 'grant'])->name('exemptions.grant');
        Route::post('/dues/{due}/payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::post('/payments/{payment}/void', [PaymentController::class, 'void'])->name('payments.void');
        Route::post('/exemptions/{exemption}/revoke', [ExemptionController::class, 'revoke'])->name('exemptions.revoke');
    });

    Route::get('/fees/{feeYear}', [FeeYearController::class, 'show'])->name('fees.show');
});

Route::middleware(['auth', 'can:view-treasury'])->prefix('bank')->name('bank.')->group(function (): void {
    Route::get('/', [BankImportController::class, 'index'])->name('index');
    Route::post('/imports', [BankImportController::class, 'store'])->middleware('can:edit-treasury')->name('imports.store');
});
