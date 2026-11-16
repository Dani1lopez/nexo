<?php

namespace App\Modules\Bank\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bank\Actions\ImportStatement;
use App\Modules\Bank\Http\Requests\ImportRequest;
use App\Modules\Bank\Models\BankImport;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Bank\Support\InvalidStatement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BankImportController extends Controller
{
    public function index(): View
    {
        return view('bank.index', [
            'movements' => BankMovement::query()->orderByDesc('occurred_on')->orderByDesc('id')->simplePaginate(50),
            'imports' => BankImport::query()->with('user')->latest('id')->limit(10)->get(),
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
