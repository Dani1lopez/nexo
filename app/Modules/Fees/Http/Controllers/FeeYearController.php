<?php

namespace App\Modules\Fees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fees\Actions\SetFeeYear;
use App\Modules\Fees\Actions\UpdateFeeAmount;
use App\Modules\Fees\Http\Requests\FeeYearRequest;
use App\Modules\Fees\Models\FeeYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FeeYearController extends Controller
{
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

    public function show(FeeYear $feeYear): View
    {
        $dues = $feeYear->dues()->with(['member', 'exemptions', 'payments'])->get()
            ->each(fn ($due) => $due->setRelation('feeYear', $feeYear))
            ->sortBy(fn ($due) => mb_strtolower($due->member->name).'|'.$due->id)->values();

        return view('fees.show', ['feeYear' => $feeYear, 'dues' => $dues]);
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
