<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #50, slice 3: the read paths that grew with the bank and the fees have their own index,
 * so the queries stay cheap instead of scanning whole tables.
 */
class DatabaseIndexesTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function indexedColumns(string $table, string $index): array
    {
        $rows = DB::select("PRAGMA index_info('{$index}')");

        return array_map(fn ($row): string => (string) $row->name, $rows);
    }

    /** @return list<string> */
    private function compiledPlan(string $query): string
    {
        return implode("\n", array_map(
            fn ($row): string => (string) $row->detail,
            DB::select('EXPLAIN QUERY PLAN '.$query),
        ));
    }

    public function test_the_read_paths_have_their_indexes(): void
    {
        $expected = [
            'payments_bank_movement_id_voided_at_index' => ['bank_movement_id', 'voided_at'],
            'payments_due_id_id_index' => ['due_id', 'id'],
            'exemptions_due_id_id_index' => ['due_id', 'id'],
            'dues_fee_year_id_index' => ['fee_year_id'],
            'members_status_name_index' => ['status', 'name'],
            'bank_movements_occurred_on_id_index' => ['occurred_on', 'id'],
        ];

        foreach ($expected as $index => $columns) {
            $table = explode('_', $index)[0];
            $table = match ($table) {
                'bank' => 'bank_movements',
                default => $table,
            };

            $this->assertSame($columns, $this->indexedColumns($table, $index), "Falta el índice {$index}.");
        }
    }

    public function test_the_bank_and_fee_read_paths_use_their_index(): void
    {
        $this->assertStringContainsString(
            'payments_bank_movement_id_voided_at_index',
            $this->compiledPlan('SELECT * FROM payments WHERE bank_movement_id = 1 AND voided_at IS NULL'),
        );

        $this->assertStringContainsString(
            'dues_fee_year_id_index',
            $this->compiledPlan('SELECT * FROM dues WHERE fee_year_id = 1'),
        );

        $this->assertStringContainsString(
            'members_status_name_index',
            $this->compiledPlan("SELECT * FROM members WHERE status = 'active' ORDER BY name"),
        );

        $this->assertStringContainsString(
            'bank_movements_occurred_on_id_index',
            $this->compiledPlan('SELECT * FROM bank_movements ORDER BY occurred_on DESC, id DESC'),
        );
    }
}
