<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Settlement;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LedgerSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_nets_two_way_debts_and_preserves_history(): void
    {
        $ledgerService = app(LedgerService::class);

        $neo = User::query()->create([
            'name' => 'Neo',
            'username' => 'neo',
            'email' => 'neo@example.com',
            'password' => Hash::make('password123'),
            'avatar_path' => 'avatars/neo.jpg',
        ]);

        $rendi = User::query()->create([
            'name' => 'Rendi',
            'username' => 'rendi',
            'email' => 'rendi@example.com',
            'password' => Hash::make('password123'),
        ]);

        $firstBill = Bill::query()->create([
            'title' => 'McD Tendean',
            'merchant' => 'McD Tendean',
            'transaction_date' => '2026-05-02',
            'paid_by_user_id' => $neo->id,
            'created_by_user_id' => $neo->id,
            'receipt_parse_status' => 'manual',
        ]);

        $firstItem = $firstBill->items()->create([
            'name' => 'Paket Rendi',
            'quantity' => 1,
            'unit_price' => 78000,
            'total_amount' => 78000,
            'source' => 'manual',
            'sort_order' => 0,
        ]);

        $firstItem->splits()->create([
            'debtor_user_id' => $rendi->id,
            'amount' => 78000,
            'sort_order' => 0,
        ]);

        $ledgerService->syncBill($firstBill);

        $secondBill = Bill::query()->create([
            'title' => 'Kopi sore',
            'merchant' => 'Kopi Tuku',
            'transaction_date' => '2026-05-05',
            'paid_by_user_id' => $rendi->id,
            'created_by_user_id' => $rendi->id,
            'receipt_parse_status' => 'manual',
        ]);

        $secondItem = $secondBill->items()->create([
            'name' => 'Kopi Neo',
            'quantity' => 1,
            'unit_price' => 30000,
            'total_amount' => 30000,
            'source' => 'manual',
            'sort_order' => 0,
        ]);

        $secondItem->splits()->create([
            'debtor_user_id' => $neo->id,
            'amount' => 30000,
            'sort_order' => 0,
        ]);

        $ledgerService->syncBill($secondBill);

        $summary = $ledgerService->getDashboardSummary($neo);
        $filteredHistory = $ledgerService->getHistory($neo, $rendi->id);

        $this->assertSame(48000, $summary['total_receivable']);
        $this->assertSame(0, $summary['total_payable']);
        $this->assertSame(48000, $summary['receivables_widget']['total_amount']);
        $this->assertCount(1, $summary['receivables_widget']['groups']);
        $this->assertCount(2, $ledgerService->getHistory($neo));
        $this->assertCount(2, $filteredHistory);
        $this->assertTrue($filteredHistory->every(fn (array $entry): bool => $entry['counterparty_id'] === $rendi->id));

        $rendiSummary = $ledgerService->getDashboardSummary($rendi);
        $this->assertSame('avatars/neo.jpg', $rendiSummary['payables_widget']['groups'][0]['counterparty']->avatar_path ?? null);

        $settlement = Settlement::query()->create([
            'from_user_id' => $rendi->id,
            'to_user_id' => $neo->id,
            'created_by_user_id' => $neo->id,
            'amount' => 18000,
            'paid_at' => '2026-05-06',
        ]);

        $ledgerService->syncSettlement($settlement);

        $afterSettlement = $ledgerService->getDashboardSummary($neo);

        $this->assertSame(30000, $afterSettlement['total_receivable']);
        $this->assertSame(0, $afterSettlement['total_payable']);
        $this->assertSame(30000, $afterSettlement['receivables_widget']['total_amount']);
        $this->assertCount(1, $afterSettlement['receivables_widget']['groups']);
        $this->assertCount(3, $afterSettlement['receivables_widget']['groups'][0]['entries']);
        $this->assertSame(30000, $afterSettlement['receivables_widget']['groups'][0]['total_amount']);
        $this->assertTrue(collect($afterSettlement['receivables_widget']['groups'][0]['entries'])->contains(fn (array $entry): bool => $entry['is_offset']));
        $this->assertCount(3, $ledgerService->getHistory($neo));
    }
}
