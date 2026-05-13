<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Settlement;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
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

    public function test_record_receivable_paid_in_full_creates_settlement_and_clears_piutang(): void
    {
        $ledgerService = app(LedgerService::class);

        $neo = User::query()->create([
            'name' => 'Neo',
            'username' => 'neo',
            'email' => 'neo@example.com',
            'password' => Hash::make('password123'),
        ]);

        $rendi = User::query()->create([
            'name' => 'Rendi',
            'username' => 'rendi',
            'email' => 'rendi@example.com',
            'password' => Hash::make('password123'),
        ]);

        $bill = Bill::query()->create([
            'title' => 'McD',
            'merchant' => 'McD',
            'transaction_date' => '2026-05-02',
            'paid_by_user_id' => $neo->id,
            'created_by_user_id' => $neo->id,
            'receipt_parse_status' => 'manual',
        ]);

        $item = $bill->items()->create([
            'name' => 'Paket Rendi',
            'quantity' => 1,
            'unit_price' => 50000,
            'total_amount' => 50000,
            'source' => 'manual',
            'sort_order' => 0,
        ]);

        $item->splits()->create([
            'debtor_user_id' => $rendi->id,
            'amount' => 50000,
            'sort_order' => 0,
        ]);

        $ledgerService->syncBill($bill);

        $before = $ledgerService->getDashboardSummary($neo);
        $this->assertSame(50000, $before['total_receivable']);
        $this->assertCount(1, $before['receivables_widget']['groups']);

        Auth::login($neo);
        $settlement = $ledgerService->recordReceivablePaidInFull($neo, $rendi->id, 'Test lunas');

        $this->assertSame($rendi->id, $settlement->from_user_id);
        $this->assertSame($neo->id, $settlement->to_user_id);
        $this->assertSame(50000, (int) $settlement->amount);

        $after = $ledgerService->getDashboardSummary($neo);
        $this->assertSame(0, $after['total_receivable']);
        $this->assertSame(0, $after['receivables_widget']['total_amount']);
        $this->assertCount(0, $after['receivables_widget']['groups']);
    }

    public function test_record_receivable_paid_in_full_throws_when_no_piutang(): void
    {
        $ledgerService = app(LedgerService::class);

        $neo = User::query()->create([
            'name' => 'Neo',
            'username' => 'neo2',
            'email' => 'neo2@example.com',
            'password' => Hash::make('password123'),
        ]);

        $rendi = User::query()->create([
            'name' => 'Rendi',
            'username' => 'rendi2',
            'email' => 'rendi2@example.com',
            'password' => Hash::make('password123'),
        ]);

        Auth::login($neo);

        $this->expectException(ValidationException::class);
        $ledgerService->recordReceivablePaidInFull($neo, $rendi->id);
    }

    public function test_record_receivable_partial_reduces_piutang(): void
    {
        $ledgerService = app(LedgerService::class);

        $neo = User::query()->create([
            'name' => 'Neo',
            'username' => 'neo3',
            'email' => 'neo3@example.com',
            'password' => Hash::make('password123'),
        ]);

        $rendi = User::query()->create([
            'name' => 'Rendi',
            'username' => 'rendi3',
            'email' => 'rendi3@example.com',
            'password' => Hash::make('password123'),
        ]);

        $bill = Bill::query()->create([
            'title' => 'Kopi',
            'merchant' => 'Kopi',
            'transaction_date' => '2026-05-02',
            'paid_by_user_id' => $neo->id,
            'created_by_user_id' => $neo->id,
            'receipt_parse_status' => 'manual',
        ]);

        $item = $bill->items()->create([
            'name' => 'Kopi Rendi',
            'quantity' => 1,
            'unit_price' => 90000,
            'total_amount' => 90000,
            'source' => 'manual',
            'sort_order' => 0,
        ]);

        $item->splits()->create([
            'debtor_user_id' => $rendi->id,
            'amount' => 90000,
            'sort_order' => 0,
        ]);

        $ledgerService->syncBill($bill);

        Auth::login($neo);
        $ledgerService->recordReceivablePartial($neo, $rendi->id, 40000, 'Sebagian');

        $after = $ledgerService->getDashboardSummary($neo);
        $this->assertSame(50000, $after['total_receivable']);
        $this->assertSame(50000, $after['receivables_widget']['groups'][0]['total_amount']);
    }

    public function test_record_receivable_partial_throws_when_amount_exceeds_net(): void
    {
        $ledgerService = app(LedgerService::class);

        $neo = User::query()->create([
            'name' => 'Neo',
            'username' => 'neo4',
            'email' => 'neo4@example.com',
            'password' => Hash::make('password123'),
        ]);

        $rendi = User::query()->create([
            'name' => 'Rendi',
            'username' => 'rendi4',
            'email' => 'rendi4@example.com',
            'password' => Hash::make('password123'),
        ]);

        $bill = Bill::query()->create([
            'title' => 'Snack',
            'merchant' => 'Indomaret',
            'transaction_date' => '2026-05-02',
            'paid_by_user_id' => $neo->id,
            'created_by_user_id' => $neo->id,
            'receipt_parse_status' => 'manual',
        ]);

        $item = $bill->items()->create([
            'name' => 'Snack',
            'quantity' => 1,
            'unit_price' => 10000,
            'total_amount' => 10000,
            'source' => 'manual',
            'sort_order' => 0,
        ]);

        $item->splits()->create([
            'debtor_user_id' => $rendi->id,
            'amount' => 10000,
            'sort_order' => 0,
        ]);

        $ledgerService->syncBill($bill);

        Auth::login($neo);

        $this->expectException(ValidationException::class);
        $ledgerService->recordReceivablePartial($neo, $rendi->id, 20000, 'Kelebihan');
    }
}
