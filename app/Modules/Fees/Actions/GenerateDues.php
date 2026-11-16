<?php

namespace App\Modules\Fees\Actions;

use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\FeeYear;
use App\Modules\Members\Models\Member;

/**
 * Creates the dues a member is entitled to. It only ever adds rows: existing
 * dues are kept, so running it again never duplicates or removes anything.
 */
class GenerateDues
{
    public function forYear(FeeYear $year): void
    {
        $memberIds = Member::query()
            ->where('status', Member::ACTIVE)
            ->whereDate('joined_on', '<=', "{$year->year}-12-31")
            ->pluck('id');

        $this->insert($memberIds->map(fn (int $memberId): array => ['member_id' => $memberId, 'fee_year_id' => $year->id])->all());
    }

    public function forMember(Member $member): void
    {
        if ($member->status !== Member::ACTIVE) {
            return;
        }

        $years = FeeYear::query()->where('year', '>=', $member->joined_on->year)->pluck('id');

        $this->insert($years->map(fn (int $yearId): array => ['member_id' => $member->id, 'fee_year_id' => $yearId])->all());
    }

    /**
     * @param  array<int, array{member_id: int, fee_year_id: int}>  $rows
     */
    private function insert(array $rows): void
    {
        $now = now();

        foreach (array_chunk($rows, 200) as $chunk) {
            Due::query()->insertOrIgnore(array_map(fn (array $row): array => $row + ['created_at' => $now, 'updated_at' => $now], $chunk));
        }
    }
}
