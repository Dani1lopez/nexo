<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The cash base is fixed once and never recalculated: it is the money in the safe before Nexo
        // registered any cash fee payment, so adding those payments later never counts twice.
        Schema::create('cash_opening_balances', function (Blueprint $table): void {
            $table->id();
            // Who fixed the base; the row is never rewritten, so this is also its only author.
            $table->foreignId('user_id')->constrained();
            $table->unsignedInteger('amount_cents');
            $table->timestamps();
        });

        // One row only. The check in code can be raced by two simultaneous first submissions, so the
        // database holds the rule too: the index value is a constant, so a second row can never fit.
        DB::statement('CREATE UNIQUE INDEX cash_opening_balances_single_row ON cash_opening_balances ((1))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cash_opening_balances_single_row');

        Schema::dropIfExists('cash_opening_balances');
    }
};
