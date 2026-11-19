<?php

namespace App\Modules\Bank\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bank\Actions\ImportStatement;
use App\Modules\Bank\Http\Requests\ImportRequest;
use App\Modules\Bank\Models\BankExpense;
use App\Modules\Bank\Models\BankImport;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Bank\Models\OtherIncome;
use App\Modules\Bank\Support\InvalidStatement;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\Payment;
use App\Modules\Members\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BankImportController extends Controller
{
    /** Hard cap of pending dues offered inside the reconcile forms of one page. */
    private const PENDING_DUES = 100;

    public function index(Request $request): View
    {
        $movements = BankMovement::query()->orderByDesc('occurred_on')->orderByDesc('id')->simplePaginate(50)->withQueryString();

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

        // A charge can also be classified as an expense, so the page explains what it paid for.
        $expenses = BankExpense::query()
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

        // Pending dues are filtered in SQL and capped, so the page never loads every due of
        // every year. The forms repeat this list per movement, so a name filter keeps it usable.
        $memberFilter = mb_substr(trim((string) $request->query('socio')), 0, 100);
        $filterByMember = fn (Builder $query): Builder => $query->when(
            $memberFilter !== '',
            fn (Builder $filtered): Builder => $filtered->whereHas(
                'member',
                fn (Builder $members): Builder => $members->whereLike('name', '%'.$memberFilter.'%'),
            ),
        );

        $pendingDues = $filterByMember(Due::query()->pending())
            ->with(['member', 'feeYear', 'exemptions', 'payments'])
            ->orderBy(Member::query()->select('name')->whereColumn('members.id', 'dues.member_id'))
            ->orderBy('dues.id')
            ->limit(self::PENDING_DUES + 1)
            ->get();

        $pendingTotal = null;
        if ($pendingDues->count() > self::PENDING_DUES) {
            $pendingTotal = $filterByMember(Due::query()->pending())->count();
            $pendingDues = $pendingDues->take(self::PENDING_DUES);
        }

        return view('bank.index', [
            'movements' => $movements,
            'imports' => BankImport::query()->with('user')->latest('id')->limit(10)->get(),
            'linked' => $linked,
            'incomes' => $incomes,
            'expenses' => $expenses,
            'categories' => OtherIncome::CATEGORIES,
            'remaining' => $remaining,
            'pendingDues' => $pendingDues,
            'pendingCap' => self::PENDING_DUES,
            'pendingTotal' => $pendingTotal,
            'memberFilter' => $memberFilter,
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
