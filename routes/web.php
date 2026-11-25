<?php

use App\Modules\Access\Http\Controllers\SessionController;
use App\Modules\Access\Http\Controllers\TwoFactorController;
use App\Modules\Bank\Http\Controllers\BankExpenseController;
use App\Modules\Bank\Http\Controllers\BankImportController;
use App\Modules\Bank\Http\Controllers\OtherIncomeController;
use App\Modules\Bank\Http\Controllers\ReconcileController;
use App\Modules\Cash\Http\Controllers\CashController;
use App\Modules\Cash\Http\Controllers\TransferController;
use App\Modules\Dashboard\Http\Controllers\DashboardController;
use App\Modules\Dashboard\Http\Controllers\SummaryController;
use App\Modules\Demo\Http\Controllers\DemoController;
use App\Modules\Demo\Http\Controllers\DemoSessionController;
use App\Modules\Fees\Http\Controllers\ExemptionController;
use App\Modules\Fees\Http\Controllers\FeeYearController;
use App\Modules\Fees\Http\Controllers\PaymentController;
use App\Modules\Members\Http\Controllers\MemberController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [SessionController::class, 'create'])->name('login');
Route::post('/login', [SessionController::class, 'store']);
Route::get('/login/segundo-factor', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
Route::post('/login/segundo-factor', [TwoFactorController::class, 'challengeStore']);
Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');

// Issue #73: the public, guided demo. It needs no account and no session data, and the
// throttle bounds the cost of creating a throwaway database. Both routes also hold the session
// lock, so a concurrent /demo request cannot put an older split over a fresh one (R4-002).
Route::get('/demo', [DemoController::class, 'show'])->middleware('throttle:10,1')->block(10, 10)->name('demo.show');
Route::post('/demo/conciliar', [DemoController::class, 'reconcile'])->middleware('throttle:10,1')->block(10, 10)->name('demo.reconcile');

// Issue #74: the demo without credentials is a session over the real screens. The entry and the
// exit are explicit POSTs with the session token. The entry has its own named limiter, separate
// from the one of /demo, so a visitor of one mode never spends the budget of the other; the exit
// is never throttled, because a rate limit on leaving would trap the visitor. The mark is dropped
// by the exit, by a real login and by leaving the demo; no guard of the treasury routes changes.
//
// Every route the demo serves, plus both demo actions, holds the session lock with ->block(). A
// lock is the only way to serialize the requests of one session: the key is the session id of the
// cookie and the lock is taken before the session is read, so a request that already has the mark
// in memory cannot be written back after the exit discarded it (R1-001). The lock cannot be scoped
// to demo visitors only, because deciding that needs the session the lock protects; the cost is
// that two parallel requests of a real session queue up to ten seconds instead of racing. Routes
// the demo does not serve stay unblocked, so the blast radius is the list below.
Route::post('/demo/entrar', [DemoSessionController::class, 'store'])
    ->middleware(['guest', 'throttle:demo-entry'])
    ->block(10, 10)
    ->name('demo.enter');
Route::post('/demo/salir', [DemoSessionController::class, 'destroy'])->block(10, 10)->name('demo.exit');
Route::middleware('auth')->prefix('access/two-factor')->name('two-factor.')->group(function (): void {
    Route::get('/', [TwoFactorController::class, 'index'])->name('index');
    Route::post('/', [TwoFactorController::class, 'store'])->name('enable');
    Route::post('/confirm', [TwoFactorController::class, 'confirm'])->name('confirm');
    Route::post('/disable', [TwoFactorController::class, 'disable'])->name('disable');
});
Route::get('/dashboard', [DashboardController::class, 'show'])->middleware(['auth', 'can:view-treasury'])->block(10, 10)->name('dashboard');
Route::get('/saldos', [SummaryController::class, 'show'])->middleware(['auth', 'can:view-treasury'])->block(10, 10)->name('summary.show');

Route::middleware(['auth', 'can:view-treasury'])->prefix('caja')->name('cash.')->group(function (): void {
    Route::get('/', [CashController::class, 'index'])->block(10, 10)->name('index');
    // The base is fixed once: the request checks the role again and the action never overwrites it.
    Route::post('/saldo-inicial', [CashController::class, 'storeOpening'])->middleware('can:edit-treasury')->name('opening.store');

    // The expenses of issue #62: drafts first, a single confirmation and corrections by void.
    Route::middleware('can:edit-treasury')->group(function (): void {
        Route::post('/gastos', [CashController::class, 'storeExpense'])->name('expenses.store');
        Route::get('/gastos/{expense}/edit', [CashController::class, 'editExpense'])->name('expenses.edit');
        Route::put('/gastos/{expense}', [CashController::class, 'updateExpense'])->name('expenses.update');
        Route::post('/gastos/{expense}/confirm', [CashController::class, 'confirmExpense'])->name('expenses.confirm');
        Route::post('/gastos/{expense}/void', [CashController::class, 'voidExpense'])->name('expenses.void');
    });
});

Route::middleware(['auth', 'can:view-treasury'])->prefix('members')->name('members.')->group(function (): void {
    Route::get('/', [MemberController::class, 'index'])->block(10, 10)->name('index');

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
    Route::get('/fees', [FeeYearController::class, 'index'])->block(10, 10)->name('fees.index');

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

    Route::get('/fees/{feeYear}', [FeeYearController::class, 'show'])->block(10, 10)->name('fees.show');
});

Route::middleware(['auth', 'can:view-treasury'])->prefix('bank')->name('bank.')->group(function (): void {
    Route::get('/', [BankImportController::class, 'index'])->block(10, 10)->name('index');
    Route::post('/imports', [BankImportController::class, 'store'])->middleware('can:edit-treasury')->name('imports.store');
    Route::post('/movements/{movement}/reconcile', [ReconcileController::class, 'store'])->middleware('can:edit-treasury')->block(10, 10)->name('movements.reconcile');
    Route::post('/movements/{movement}/expenses', [BankExpenseController::class, 'store'])->middleware('can:edit-treasury')->name('movements.expenses.store');
    Route::post('/movements/{movement}/transfers', [TransferController::class, 'store'])->middleware('can:edit-treasury')->name('movements.transfers.store');
    Route::post('/transfers/{transfer}/void', [TransferController::class, 'void'])->middleware('can:edit-treasury')->name('transfers.void');
    Route::post('/expenses/{expense}/void', [BankExpenseController::class, 'void'])->middleware('can:edit-treasury')->name('expenses.void');
    Route::post('/movements/{movement}/incomes', [OtherIncomeController::class, 'store'])->middleware('can:edit-treasury')->name('movements.incomes.store');
    Route::post('/incomes/{income}/void', [OtherIncomeController::class, 'void'])->middleware('can:edit-treasury')->name('incomes.void');
    Route::post('/payments/{payment}/void', [ReconcileController::class, 'void'])->middleware('can:edit-treasury')->name('payments.void');
});
