<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_type');
            $table->foreignId('debtor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('creditor_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('effective_date');
            $table->foreignId('bill_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('bill_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('bill_split_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('settlement_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['debtor_user_id', 'creditor_user_id']);
            $table->index(['effective_date', 'entry_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
