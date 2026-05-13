<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_items', function (Blueprint $table): void {
            $table->boolean('split_per_item')->default(false)->after('source');
        });

        $billIds = DB::table('bills')->where('split_per_item', true)->pluck('id');
        if ($billIds->isNotEmpty()) {
            DB::table('bill_items')->whereIn('bill_id', $billIds->all())->update(['split_per_item' => true]);
        }

        Schema::table('bills', function (Blueprint $table): void {
            $table->dropColumn('split_per_item');
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->boolean('split_per_item')->default(false)->after('service_charge_amount');
        });

        Schema::table('bill_items', function (Blueprint $table): void {
            $table->dropColumn('split_per_item');
        });
    }
};
