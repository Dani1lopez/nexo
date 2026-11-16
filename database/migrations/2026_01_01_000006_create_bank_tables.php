<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The import log keeps who imported and how many rows, never the uploaded file itself.
        Schema::create('bank_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->unsignedInteger('imported_count');
            $table->unsignedInteger('duplicate_count');
            $table->unsignedInteger('rejected_count');
            $table->timestamps();
        });

        Schema::create('bank_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_import_id')->constrained();
            $table->date('occurred_on');
            // Signed integer cents: charges are negative.
            $table->integer('amount_cents');
            $table->string('concept');
            // Not unique: identical rows inside one file are all valid; duplicates are only detected against earlier imports.
            $table->string('fingerprint', 64)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_movements');
        Schema::dropIfExists('bank_imports');
    }
};
