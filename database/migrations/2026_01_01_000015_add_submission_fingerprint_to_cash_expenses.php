<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_expenses', function (Blueprint $table) {
            // The submitted details as they were when the form was first sent. A draft can be
            // legitimately edited afterwards, so the row cannot be the comparison basis for a
            // repeated submit: this fingerprint keeps the original submission instead.
            $table->string('submission_fingerprint')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cash_expenses', function (Blueprint $table) {
            $table->dropColumn('submission_fingerprint');
        });
    }
};
