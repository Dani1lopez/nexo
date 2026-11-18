<?php

namespace App\Modules\Fees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fees\Actions\SetFeeYear;
use App\Modules\Fees\Actions\SumDues;
use App\Modules\Fees\Actions\UpdateFeeAmount;
use App\Modules\Fees\Http\Requests\FeeYearRequest;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\FeeYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FeeYearController extends Controller
{
    /** Hard cap of rendered due cards, so a growing year never becomes an unbounded response. */
    private const DUES_PER_PAGE = 50;

    public function index(): View
    {
        return view('fees.index', ['years' => FeeYear::query()->withCount('dues')->orderByDesc('year')->get()]);
    }

    public function create(): View
    {
        return view('fees.form', ['feeYear' => new FeeYear]);
    }

    public function store(FeeYearRequest $request, SetFeeYear $setFeeYear): RedirectResponse
    {
        $feeYear = $setFeeYear->handle((int) $request->validated('year'), $request->amountCents());

        return redirect("/fees/{$feeYear->id}")->with('status', 'Cuota fijada.');
    }

    public function show(FeeYear $feeYear, SumDues $sumDues): View
    {
        // The cards are paged, but the tally below covers the whole year: it is summed in
        // chunks, never by loading every due of the year at once.
        $dues = Due::query()
            ->where('dues.fee_year_id', $feeYear->id)
            ->join('members', 'members.id', '=', 'dues.member_id')
            ->select('dues.*')
            ->with(['member', 'feeYear', 'exemptions', 'payments'])
            ->orderBy('members.name')
            ->orderBy('dues.id')
            ->paginate(self::DUES_PER_PAGE)
            ->withQueryString();

        return view('fees.show', [
            'feeYear' => $feeYear,
            'dues' => $dues,
            'totals' => $sumDues->handle(Due::query()->where('fee_year_id', $feeYear->id)),
        ]);
    }

    public function edit(FeeYear $feeYear): View
    {
        return view('fees.form', ['feeYear' => $feeYear]);
    }

    /** Only the amount can change, and only while no payment is active. */
    public function update(FeeYearRequest $request, FeeYear $feeYear, UpdateFeeAmount $updateAmount): RedirectResponse
    {
        $updateAmount->handle($feeYear, $request->amountCents());

        return redirect("/fees/{$feeYear->id}")->with('status', 'Importe guardado.');
    }
}
