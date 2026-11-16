<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->unsignedInteger('amount_cents');
            $table->timestamps();
        });

        Schema::create('dues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained();
            $table->foreignId('fee_year_id')->constrained();
            $table->timestamps();
            $table->unique(['member_id', 'fee_year_id']);
        });

        Schema::create('exemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('due_id')->constrained();
            $table->text('reason');
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoked_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exemptions');
        Schema::dropIfExists('dues');
        Schema::dropIfExists('fee_years');
    }
};
