<?php

namespace App\Modules\Bank\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bank\Actions\ImportStatement;
use App\Modules\Bank\Http\Requests\ImportRequest;
use App\Modules\Bank\Models\BankImport;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Bank\Models\OtherIncome;
use App\Modules\Bank\Support\InvalidStatement;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BankImportController extends Controller
{
    public function index(): View
    {
        $movements = BankMovement::query()->orderByDesc('occurred_on')->orderByDesc('id')->simplePaginate(50);

        // A credit can be split over several dues, so a movement lists every active payment it fed;
        // voided ones no longer count as reconciled.
        $linked = Payment::query()
            ->with(['due.member', 'due.feeYear'])
            ->whereIn('bank_movement_id', collect($movements->items())->pluck('id'))
            ->whereNull('voided_at')
            ->orderBy('id')
            ->get()
            ->groupBy('bank_movement_id');

        // A credit that is not money for the dues can be classified as other income instead. At most
        // one classification is active per movement, so the map holds one row per movement id.
        $incomes = OtherIncome::query()
            ->whereIn('bank_movement_id', collect($movements->items())->pluck('id'))
            ->whereNull('voided_at')
            ->get()
            ->keyBy('bank_movement_id');

        // What is left of each movement: its amount minus the active payments and, when it is
        // classified as other income, the whole credit that already has its destination.
        $remaining = [];
        foreach ($movements->items() as $movement) {
            $used = (int) $linked->get($movement->id, collect())->sum('amount_cents')
                + (int) ($incomes->get($movement->id)?->amount_cents ?? 0);
            $remaining[$movement->id] = max(0, $movement->amount_cents - $used);
        }

        // Demo scale: dues carry computed status, so filtering happens on the loaded year.
        $pendingDues = Due::query()
            ->with(['member', 'feeYear', 'payments', 'exemptions'])
            ->get()
            ->filter(fn (Due $due): bool => $due->activeExemption() === null && $due->pendingCents() > 0)
            ->sortBy(fn (Due $due): string => $due->member->name)
            ->values();

        return view('bank.index', [
            'movements' => $movements,
            'imports' => BankImport::query()->with('user')->latest('id')->limit(10)->get(),
            'linked' => $linked,
            'incomes' => $incomes,
            'categories' => OtherIncome::CATEGORIES,
            'remaining' => $remaining,
            'pendingDues' => $pendingDues,
        ]);
    }

    public function store(ImportRequest $request, ImportStatement $import): RedirectResponse
    {
        try {
            $result = $import->handle($request->user(), (string) file_get_contents($request->file('file')->getRealPath()));
        } catch (InvalidStatement $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return redirect('/bank')->with('import_report', [
            'imported' => $result['import']->imported_count,
            'duplicates' => $result['import']->duplicate_count,
            'rejected' => $result['rejected'],
        ]);
    }
}
