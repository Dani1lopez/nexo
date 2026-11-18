<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read-path indexes for the listings and the rules that grew with the bank and the fees.
 * SQLite does not create an index for a foreign key by itself, so the queries that filter
 * by a movement, a due, a fee year or the member status had no index at all.
 */
return new class extends Migration
{
    /** @var array<string, list<list<string>>> */
    private const INDEXES = [
        'payments' => [
            ['bank_movement_id', 'voided_at'],
            ['due_id', 'id'],
        ],
        'exemptions' => [
            ['due_id', 'id'],
        ],
        'dues' => [
            ['fee_year_id'],
        ],
        'members' => [
            ['status', 'name'],
        ],
        'bank_movements' => [
            ['occurred_on', 'id'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $columns) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $columns) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($columns));
            }
        }
    }
};
