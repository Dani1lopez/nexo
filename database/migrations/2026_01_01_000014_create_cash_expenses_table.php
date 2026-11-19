<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_expenses', function (Blueprint $table) {
            $table->id();
            // Who registered the draft; a correction voids the row, it never rewrites the author.
            $table->foreignId('user_id')->constrained();
            $table->string('category');
            $table->string('concept');
            $table->unsignedInteger('amount_cents');
            $table->date('spent_on');
            // Without a receipt the reason is mandatory (checked in the request and the action).
            $table->boolean('has_receipt')->default(false);
            $table->text('reason')->nullable();
            // One random key per rendered form: a repeated submit is not a second draft.
            $table->uuid('submission_key')->unique();
            // A draft is confirmed once; the cash figure only counts confirmed, non-voided rows.
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            // Read path of /caja and /saldos: confirmed rows with voided_at still null.
            $table->index(['confirmed_at', 'voided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_expenses');
    }
};
