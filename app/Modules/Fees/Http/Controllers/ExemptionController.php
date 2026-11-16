<?php

namespace App\Modules\Fees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fees\Actions\GrantExemption;
use App\Modules\Fees\Actions\RevokeExemption;
use App\Modules\Fees\Http\Requests\ExemptionRequest;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\Exemption;
use Illuminate\Http\RedirectResponse;

class ExemptionController extends Controller
{
    public function grant(ExemptionRequest $request, Due $due, GrantExemption $grant): RedirectResponse
    {
        $grant->handle($due, $request->validated('reason'));

        return redirect("/fees/{$due->fee_year_id}");
    }

    public function revoke(ExemptionRequest $request, Exemption $exemption, RevokeExemption $revoke): RedirectResponse
    {
        $revoke->handle($exemption, $request->validated('reason'));

        return redirect('/fees/'.$exemption->due->fee_year_id);
    }
}
