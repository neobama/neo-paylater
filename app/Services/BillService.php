<?php

namespace App\Services;

use App\Models\Bill;
use Illuminate\Support\Facades\DB;

class BillService
{
    public function __construct(
        private readonly LedgerService $ledgerService,
    ) {}

    public function createBill(array $data, int $createdByUserId): Bill
    {
        return DB::transaction(function () use ($data, $createdByUserId): Bill {
            $bill = Bill::query()->create([
                ...$this->extractBillAttributes($data),
                'created_by_user_id' => $createdByUserId,
            ]);

            $this->syncItems($bill, $data['items'] ?? []);
            $this->ledgerService->syncBill($bill);

            return $bill->fresh(['payer']);
        });
    }

    public function updateBill(Bill $bill, array $data): Bill
    {
        return DB::transaction(function () use ($bill, $data): Bill {
            $bill->update($this->extractBillAttributes($data));

            $bill->items()->delete();
            $this->syncItems($bill, $data['items'] ?? []);
            $this->ledgerService->syncBill($bill);

            return $bill->fresh(['payer']);
        });
    }

    private function extractBillAttributes(array $data): array
    {
        return [
            'title' => $data['title'],
            'merchant' => $data['merchant'] ?? null,
            'transaction_date' => $data['transaction_date'],
            'paid_by_user_id' => $data['paid_by_user_id'],
            'total_amount' => $data['total_amount'] ?? 0,
            'receipt_parse_status' => $data['receipt_parse_status'] ?? 'manual',
            'receipt_image_path' => $data['receipt_image_path'] ?? null,
            'receipt_parsed_at' => $data['receipt_parsed_at'] ?? null,
            'receipt_raw_json' => $data['receipt_raw_json'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function syncItems(Bill $bill, array $items): void
    {
        foreach (array_values($items) as $itemIndex => $itemData) {
            $item = $bill->items()->create([
                'name' => $itemData['name'],
                'quantity' => $itemData['quantity'],
                'unit_price' => $itemData['unit_price'],
                'total_amount' => $itemData['total_amount'],
                'source' => $itemData['source'] ?? 'manual',
                'sort_order' => $itemIndex,
                'raw_payload' => $itemData['raw_payload'] ?? null,
            ]);

            foreach (array_values($itemData['splits'] ?? []) as $splitIndex => $splitData) {
                $item->splits()->create([
                    'debtor_user_id' => $splitData['debtor_user_id'],
                    'amount' => $splitData['amount'],
                    'notes' => $splitData['notes'] ?? null,
                    'sort_order' => $splitIndex,
                ]);
            }
        }
    }
}
