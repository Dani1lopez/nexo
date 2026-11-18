<?php

namespace App\Modules\Members\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Members\Http\Requests\MemberRequest;
use App\Modules\Members\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemberController extends Controller
{
    /** Hard cap of rendered members, so the list keeps paging instead of growing forever. */
    private const MEMBERS_PER_PAGE = 25;

    private const FILTERS = [
        'activos' => Member::ACTIVE,
        'bajas' => Member::INACTIVE,
        'todos' => null,
    ];

    public function index(Request $request): View
    {
        $filter = array_key_exists((string) $request->query('estado'), self::FILTERS) ? (string) $request->query('estado') : 'activos';
        $status = self::FILTERS[$filter];

        return view('members.index', [
            'members' => Member::query()
                ->when($status !== null, fn ($query) => $query->where('status', $status))
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(self::MEMBERS_PER_PAGE)
                ->withQueryString(),
            'filter' => $filter,
        ]);
    }

    public function create(): View
    {
        return view('members.form', ['member' => new Member(['joined_on' => now()->toDateString()])]);
    }

    public function store(MemberRequest $request): RedirectResponse
    {
        Member::create($request->validated());

        return redirect('/members')->with('status', 'Socio creado.');
    }

    public function edit(Member $member): View
    {
        return view('members.form', ['member' => $member]);
    }

    public function update(MemberRequest $request, Member $member): RedirectResponse
    {
        $member->update($request->validated());

        return redirect('/members')->with('status', 'Cambios guardados.');
    }

    public function deactivate(Member $member): RedirectResponse
    {
        $member->forceFill(['status' => Member::INACTIVE])->save();

        return redirect('/members')->with('status', 'Socio dado de baja.');
    }

    public function reactivate(Member $member): RedirectResponse
    {
        $member->forceFill(['status' => Member::ACTIVE])->save();

        return redirect('/members')->with('status', 'Socio reactivado.');
    }
}
