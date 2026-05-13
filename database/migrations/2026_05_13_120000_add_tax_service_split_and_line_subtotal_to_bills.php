<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->unsignedBigInteger('tax_amount')->default(0)->after('total_amount');
            $table->unsignedBigInteger('service_charge_amount')->default(0)->after('tax_amount');
            $table->boolean('split_per_item')->default(false)->after('service_charge_amount');
        });

        Schema::table('bill_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('line_subtotal')->nullable()->after('unit_price');
        });

        DB::table('bill_items')->update([
            'line_subtotal' => DB::raw('total_amount'),
        ]);
    }

    public function down(): void
    {
        Schema::table('bill_items', function (Blueprint $table): void {
            $table->dropColumn('line_subtotal');
        });

        Schema::table('bills', function (Blueprint $table): void {
            $table->dropColumn(['tax_amount', 'service_charge_amount', 'split_per_item']);
        });
    }
};
