<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use App\Modules\Bank\Models\BankImport;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Fees\Actions\SetFeeYear;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\Exemption;
use App\Modules\Fees\Models\FeeYear;
use App\Modules\Fees\Models\Payment;
use App\Modules\Members\Models\Member;
use Illuminate\Support\Str;
use Tests\Support\AuthTestCase;

/**
 * Issue #50, slice 2: every listing has a hard cap so a growing association never
 * loads or renders an unbounded number of rows in one request.
 */
class ListingLimitsTest extends AuthTestCase
{
    private function member(string $name, string $status = 'active'): Member
    {
        $member = Member::create([
            'name' => $name,
            'email' => Str::slug($name).'@example.test',
            'joined_on' => '2024-01-15',
        ]);
        $member->forceFill(['status' => $status])->save();

        return $member;
    }

    /** Members named Socia 001, Socia 002 … so the order is unambiguous. */
    private function members(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->member(sprintf('Socia %03d', $i));
        }
    }

    private function year(int $year = 2026, int $cents = 3000): FeeYear
    {
        return app(SetFeeYear::class)->handle($year, $cents);
    }

    private function treasury(): User
    {
        return User::where('email', 'tesoreria@example.test')->first()
            ?? $this->account('tesoreria@example.test', 'treasury', 'treasury-secret-value');
    }

    private function board(): User
    {
        return User::where('email', 'junta@example.test')->first()
            ?? $this->account('junta@example.test', 'board', 'board-secret-value');
    }

    private function movement(int $cents, string $concept = 'TRANSFERENCIA CONJUNTA'): BankMovement
    {
        $import = BankImport::forceCreate([
            'user_id' => $this->treasury()->id,
            'imported_count' => 1,
            'duplicate_count' => 0,
            'rejected_count' => 0,
        ]);

        return BankMovement::forceCreate([
            'bank_import_id' => $import->id,
            'occurred_on' => '2026-02-14',
            'amount_cents' => $cents,
            'concept' => $concept,
            'fingerprint' => BankMovement::fingerprint('2026-02-14', $cents, $concept),
        ]);
    }

    private function pay(Due $due, int $cents, bool $voided = false): Payment
    {
        return Payment::forceCreate([
            'due_id' => $due->id,
            'bank_movement_id' => null,
            'amount_cents' => $cents,
            'paid_on' => '2026-03-01',
            'method' => Payment::CASH,
            'submission_key' => (string) Str::uuid(),
            'voided_at' => $voided ? now() : null,
            'void_reason' => $voided ? 'Anulado para la prueba.' : null,
        ]);
    }

    public function test_the_members_screen_renders_twenty_five_rows_and_pages_the_rest(): void
    {
        $this->members(26);
        $this->actingAs($this->board());

        $first = $this->get('/members')->assertOk();
        $first->assertSee('Socia 001')->assertSee('Socia 025')->assertDontSee('Socia 026');
        $first->assertSee('page=2', false);

        $this->get('/members?page=2')->assertOk()->assertSee('Socia 026')->assertDontSee('Socia 001');
    }

    public function test_the_member_filter_survives_paging(): void
    {
        $this->members(26);
        $this->member('Zoe 001', 'inactive');
        $this->actingAs($this->board());

        $this->get('/members?estado=todos')->assertOk()
            ->assertSee('estado=todos&amp;page=2', false);

        $this->get('/members?estado=todos&page=2')->assertOk()->assertSee('Zoe 001');
    }

    public function test_the_fee_screen_pages_the_dues_and_keeps_the_whole_year_totals(): void
    {
        $this->members(210);
        $year = $this->year(2026, 3000);
        $this->actingAs($this->board());

        $first = $this->get("/fees/{$year->id}")->assertOk();
        $this->assertSame(50, substr_count($first->getContent(), 'class="entry due'));
        // The tally covers every due of the year, not only the rendered page: 210 × 30,00 €.
        $first->assertSee('6.300,00 €');
        $first->assertSee('page=2', false);

        $last = $this->get("/fees/{$year->id}?page=5")->assertOk();
        $this->assertSame(10, substr_count($last->getContent(), 'class="entry due'));
    }

    public function test_the_bank_screen_caps_the_pending_dues_and_filters_by_member(): void
    {
        $this->members(105);
        $this->year(2026, 3000);
        $this->movement(1000);
        $this->actingAs($this->treasury());

        $page = $this->get('/bank')->assertOk();
        $this->assertSame(100, substr_count($page->getContent(), 'name="allocations['));
        $page->assertSee('Se muestran las primeras 100 cuotas pendientes de 105');
        $page->assertDontSee('Socia 105');

        $filtered = $this->get('/bank?socio='.urlencode('Socia 105'))->assertOk();
        $this->assertSame(1, substr_count($filtered->getContent(), 'name="allocations['));
        $filtered->assertSee('Socia 105');
    }

    public function test_the_pending_scope_matches_the_php_rule(): void
    {
        $this->members(6);
        $this->year(2026, 3000);
        $dues = Due::orderBy('id')->get();

        $this->pay($dues[1], 3000);
        $this->pay($dues[2], 1000);
        Exemption::forceCreate(['due_id' => $dues[3]->id, 'reason' => 'Situación económica.']);
        Exemption::forceCreate(['due_id' => $dues[4]->id, 'reason' => 'Situación económica.', 'revoked_at' => now(), 'revoked_reason' => 'Mejoró.']);
        $this->pay($dues[5], 3000, voided: true);

        $expected = Due::with(['feeYear', 'exemptions', 'payments'])->get()
            ->filter(fn (Due $due): bool => $due->pendingCents() > 0)
            ->pluck('id')->sort()->values()->all();

        $this->assertSame($expected, Due::query()->pending()->orderBy('dues.id')->pluck('dues.id')->all());
    }

    public function test_the_balances_screen_sums_movements_beyond_one_chunk(): void
    {
        $this->members(210);
        $this->year(2026, 3000);

        $import = BankImport::forceCreate([
            'user_id' => $this->treasury()->id,
            'imported_count' => 205,
            'duplicate_count' => 0,
            'rejected_count' => 0,
        ]);
        $now = now();
        $rows = [];
        for ($i = 1; $i <= 205; $i++) {
            $rows[] = [
                'bank_import_id' => $import->id,
                'occurred_on' => '2026-02-14',
                'amount_cents' => 100,
                'concept' => 'TRANSFERENCIA '.$i,
                'fingerprint' => hash('sha256', 'credit-'.$i),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        BankMovement::query()->insert($rows);

        $this->actingAs($this->treasury())->get('/saldos')->assertOk()
            ->assertSee('205 abonos sin conciliar')
            ->assertSee('205,00 €')
            ->assertSee('6.300,00 €');
    }
}
