<?php

namespace App\Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Actions\BuildBalances;
use Illuminate\View\View;

class SummaryController extends Controller
{
    public function show(BuildBalances $balances): View
    {
        return view('summary', ['balances' => $balances->handle()]);
    }
}
