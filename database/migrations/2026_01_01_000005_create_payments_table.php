<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('due_id')->constrained();
            $table->unsignedInteger('amount_cents');
            $table->date('paid_on');
            $table->string('method');
            $table->string('note')->nullable();
            // One random key per rendered form: a repeated submit of the same form is not a new payment.
            $table->uuid('submission_key')->unique();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
