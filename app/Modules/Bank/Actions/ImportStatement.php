<?php

namespace App\Modules\Bank\Actions;

use App\Modules\Access\Models\User;
use App\Modules\Bank\Models\BankImport;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Bank\Support\InvalidStatement;
use App\Modules\Bank\Support\StatementParser;
use Illuminate\Support\Facades\DB;

class ImportStatement
{
    public function __construct(private readonly StatementParser $parser) {}

    /**
     * Imports the valid rows of a statement. The file content is processed in memory and never stored.
     *
     * @return array{import: BankImport, rejected: list<array{line: int, reason: string}>}
     *
     * @throws InvalidStatement
     */
    public function handle(User $user, string $content): array
    {
        $parsed = $this->parser->parse($content);

        // Known fingerprints are read inside the transaction (immediate on SQLite) so two simultaneous
        // imports of the same statement cannot both insert it.
        return DB::transaction(function () use ($user, $parsed): array {
            $fingerprints = array_map(fn (array $row) => BankMovement::fingerprint($row['date'], $row['cents'], $row['concept']), $parsed['rows']);

            // Only earlier imports count: identical rows inside this file are all valid.
            $known = [];
            foreach (array_chunk(array_unique($fingerprints), 500) as $chunk) {
                foreach (BankMovement::query()->whereIn('fingerprint', $chunk)->pluck('fingerprint') as $fingerprint) {
                    $known[$fingerprint] = true;
                }
            }

            $now = now();
            $new = [];
            $duplicates = 0;
            foreach ($parsed['rows'] as $position => $row) {
                if (isset($known[$fingerprints[$position]])) {
                    $duplicates++;

                    continue;
                }
                $new[] = ['occurred_on' => $row['date'], 'amount_cents' => $row['cents'], 'concept' => $row['concept'], 'fingerprint' => $fingerprints[$position], 'created_at' => $now, 'updated_at' => $now];
            }

            $import = BankImport::forceCreate([
                'user_id' => $user->id,
                'imported_count' => count($new),
                'duplicate_count' => $duplicates,
                'rejected_count' => count($parsed['rejected']),
            ]);

            foreach (array_chunk($new, 100) as $chunk) {
                BankMovement::query()->insert(array_map(fn (array $movement) => $movement + ['bank_import_id' => $import->id], $chunk));
            }

            return ['import' => $import, 'rejected' => $parsed['rejected']];
        });
    }
}
