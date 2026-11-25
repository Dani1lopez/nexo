<?php

namespace App\Modules\Demo\Actions;

use App\Modules\Access\Models\User;
use App\Modules\Bank\Actions\ImportStatement;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Demo\Support\DemoScenario;
use App\Modules\Fees\Actions\SetFeeYear;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Support\Money;
use App\Modules\Members\Models\Member;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Opens the disposable in-memory database of the guided demo, seeds the fixed scenario and runs
 * the callback against it. The scenario is written with the real actions, never by hand.
 *
 * The treasury connection is restored in a finally block, so a failing reconciliation or a
 * failing seeder can never leave the request pointed at the real database. There is no fallback:
 * if the demo connection cannot be created, the request fails instead of using another one.
 */
final class OpenDemoScenario
{
    public const CONNECTION = 'demo';

    public const FEE_CENTS = 4000;

    public const CREDIT_CENTS = 10000;

    public const CONCEPT = 'TRANSFERENCIA DE EJEMPLO';

    /**
     * @template T
     *
     * @param  callable(DemoScenario): T  $callback
     * @return T
     */
    public function handle(callable $callback): mixed
    {
        $original = DB::getDefaultConnection();

        // The demo must never run over the treasury connection, not even if the configuration
        // is wrong: failing here is safer than migrating data that belongs to the association.
        if ($original === self::CONNECTION) {
            throw new RuntimeException('La demostración no puede usar la conexión principal.');
        }

        try {
            DB::purge(self::CONNECTION);
            DB::setDefaultConnection(self::CONNECTION);

            // The migrations are the real ones, applied to this request's throwaway database.
            Artisan::call('migrate', ['--force' => true]);

            return $callback($this->seed());
        } finally {
            DB::setDefaultConnection($original);
            DB::purge(self::CONNECTION);
        }
    }

    private function seed(): DemoScenario
    {
        $today = now();
        $year = (int) $today->year;

        // The importer needs an author, and the import log references it. This is a disposable
        // internal row, not a visitor account and not a login: the whole database disappears.
        $actor = User::provision('Equipo de ejemplo', 'equipo@ejemplo.test', Str::random(64), 'treasury');

        $members = [
            DemoScenario::SOCIA_A => Member::create([
                'name' => 'Socia Ejemplo A',
                'email' => 'socia.a@ejemplo.test',
                'joined_on' => "{$year}-01-01",
            ]),
            DemoScenario::SOCIO_B => Member::create([
                'name' => 'Socio Ejemplo B',
                'email' => 'socio.b@ejemplo.test',
                'joined_on' => "{$year}-01-01",
            ]),
        ];

        // The real action creates one 40,00 € due per active member for the current year.
        app(SetFeeYear::class)->handle($year, self::FEE_CENTS);

        // The statement is generated in memory and imported with the real action: no file is
        // written and no upload is involved. The date is the day of the visit.
        $csv = implode("\n", [
            'fecha;importe;concepto',
            $today->format('d/m/Y').';'.Money::input(self::CREDIT_CENTS).';'.self::CONCEPT,
        ]);
        $import = app(ImportStatement::class)->handle($actor, $csv)['import'];

        $movement = BankMovement::query()
            ->where('bank_import_id', $import->id)
            ->sole();

        $rows = [];
        foreach ($members as $key => $member) {
            // Resolve the due through its member and year, not through an id assumed by the seeder.
            $dueId = Due::query()
                ->where('member_id', $member->id)
                ->whereHas('feeYear', fn ($query) => $query->where('year', $year))
                ->value('id');

            if ($dueId === null) {
                throw new RuntimeException('La cuota de ejemplo no se ha generado.');
            }

            $rows[$key] = [
                'name' => $member->name,
                'email' => $member->email,
                'dueId' => (int) $dueId,
            ];
        }

        return new DemoScenario(
            year: $year,
            feeCents: self::FEE_CENTS,
            creditCents: self::CREDIT_CENTS,
            concept: self::CONCEPT,
            occurredOn: $today->toDateString(),
            movementId: (int) $movement->id,
            actorId: (int) $actor->id,
            members: $rows,
        );
    }
}
