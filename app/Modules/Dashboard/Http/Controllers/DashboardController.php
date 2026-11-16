<?php

namespace App\Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Actions\BuildOverview;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function show(BuildOverview $overview): View
    {
        return view('dashboard', ['overview' => $overview->handle()]);
    }
}
