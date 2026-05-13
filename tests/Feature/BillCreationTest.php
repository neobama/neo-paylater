<?php

namespace Tests\Feature;

use App\Filament\Resources\Bills\BillResource;
use App\Models\Bill;
use App\Models\User;
use App\Services\BillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class BillCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_bill_preparation_fills_single_split_defaults(): void
    {
        $prepared = BillResource::prepareBillData([
            'title' => 'Test Bill',
            'merchant' => 'Test Merchant',
            'transaction_date' => '2026-05-12',
            'paid_by_user_id' => 1,
            'notes' => 'Bill test',
            'items' => [
                [
                    'name' => 'Paket umum',
                    'quantity' => 1,
                    'unit_price' => null,
                    'total_amount' => 10000,
                    'splits' => [
                        [
                            'debtor_user_id' => null,
                            'amount' => null,
                            'notes' => null,
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame(10000, $prepared['total_amount']);
        $this->assertSame(10000, $prepared['items'][0]['unit_price']);
        $this->assertSame(1, $prepared['items'][0]['splits'][0]['debtor_user_id']);
        $this->assertSame(10000, $prepared['items'][0]['splits'][0]['amount']);
    }

    public function test_bill_service_creates_bill_with_nested_items_and_splits(): void
    {
        User::query()->create([
            'id' => 1,
            'name' => 'Neo',
            'username' => 'neo',
            'email' => 'neo@example.com',
            'password' => 'password123',
        ]);

        User::query()->create([
            'id' => 2,
            'name' => 'Rendi',
            'username' => 'rendi',
            'email' => 'rendi@example.com',
            'password' => 'password123',
        ]);

        $prepared = BillResource::prepareBillData([
            'title' => 'Test Bill',
            'merchant' => 'Merchant',
            'transaction_date' => '2026-05-12',
            'paid_by_user_id' => 1,
            'items' => [
                [
                    'name' => 'Paket Rendi',
                    'quantity' => 1,
                    'unit_price' => 12000,
                    'total_amount' => 12000,
                    'splits' => [
                        [
                            'debtor_user_id' => 2,
                            'amount' => 12000,
                        ],
                    ],
                ],
            ],
        ]);

        $bill = app(BillService::class)->createBill($prepared, 1);

        $this->assertInstanceOf(Bill::class, $bill);
        $this->assertDatabaseHas('bills', [
            'id' => $bill->id,
            'title' => 'Test Bill',
            'total_amount' => 12000,
        ]);
        $this->assertDatabaseHas('bill_items', [
            'bill_id' => $bill->id,
            'name' => 'Paket Rendi',
        ]);
        $this->assertDatabaseHas('bill_splits', [
            'debtor_user_id' => 2,
            'amount' => 12000,
        ]);
    }

    public function test_tagged_user_can_access_bill_and_see_their_assigned_amount(): void
    {
        $neo = User::query()->create([
            'name' => 'Neo',
            'username' => 'neo',
            'email' => 'neo@example.com',
            'password' => 'password123',
        ]);

        $rendi = User::query()->create([
            'name' => 'Rendi',
            'username' => 'rendi',
            'email' => 'rendi@example.com',
            'password' => 'password123',
        ]);

        $prepared = BillResource::prepareBillData([
            'title' => 'Dinner',
            'merchant' => 'McD',
            'transaction_date' => '2026-05-12',
            'paid_by_user_id' => $neo->id,
            'items' => [
                [
                    'name' => 'Paket Rendi',
                    'quantity' => 1,
                    'unit_price' => 15000,
                    'total_amount' => 15000,
                    'splits' => [
                        [
                            'debtor_user_id' => $rendi->id,
                            'amount' => 15000,
                        ],
                    ],
                ],
            ],
        ]);

        $bill = app(BillService::class)->createBill($prepared, $neo->id);

        Auth::login($rendi);

        $this->assertTrue(BillResource::canAccessRecord($bill));
        $this->assertSame(15000, BillResource::getAssignedAmountForUser($bill, $rendi->id));
    }
}
