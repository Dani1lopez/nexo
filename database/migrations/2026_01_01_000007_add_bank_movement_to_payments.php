<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            // The statement movement a payment came from; null when it was registered by hand.
            $table->foreignId('bank_movement_id')->nullable()->after('due_id')->constrained('bank_movements');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bank_movement_id');
        });
    }
};
