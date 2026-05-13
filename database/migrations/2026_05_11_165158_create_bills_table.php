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
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('merchant')->nullable();
            $table->date('transaction_date');
            $table->foreignId('paid_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->string('receipt_parse_status')->default('manual');
            $table->string('receipt_image_path')->nullable();
            $table->timestamp('receipt_parsed_at')->nullable();
            $table->json('receipt_raw_json')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['transaction_date', 'paid_by_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
