<?php

namespace App\Modules\Fees;

use App\Modules\Fees\Actions\GenerateDues;
use App\Modules\Members\Models\Member;
use Illuminate\Support\ServiceProvider;

class FeesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // A new member, or a corrected join date, may entitle them to dues of years already fixed.
        Member::created(fn (Member $member) => app(GenerateDues::class)->forMember($member));
        Member::updated(function (Member $member): void {
            if ($member->wasChanged('joined_on')) {
                app(GenerateDues::class)->forMember($member);
            }
        });
    }
}
