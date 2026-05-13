<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\LedgerEntry;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LedgerService
{
    public function syncBill(Bill $bill): void
    {
        DB::transaction(function () use ($bill): void {
            $bill->loadMissing([
                'payer:id,name,avatar_path',
                'items.splits.debtor:id,name,avatar_path',
            ]);

            if ($bill->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Tagihan harus memiliki minimal satu item.',
                ]);
            }

            $entries = [];
            $totalAmount = 0;

            foreach ($bill->items as $item) {
                $itemTotal = (int) $item->total_amount;
                $splitTotal = (int) $item->splits->sum('amount');

                if ($itemTotal <= 0) {
                    throw ValidationException::withMessages([
                        'items' => "Item {$item->name} harus memiliki total lebih dari nol.",
                    ]);
                }

                if ($splitTotal !== $itemTotal) {
                    throw ValidationException::withMessages([
                        'items' => "Pembagian untuk item {$item->name} harus sama dengan total item.",
                    ]);
                }

                $totalAmount += $itemTotal;

                foreach ($item->splits as $split) {
                    $amount = (int) $split->amount;

                    if ($amount <= 0 || $split->debtor_user_id === $bill->paid_by_user_id) {
                        continue;
                    }

                    $entries[] = [
                        'entry_type' => 'obligation',
                        'debtor_user_id' => $split->debtor_user_id,
                        'creditor_user_id' => $bill->paid_by_user_id,
                        'amount' => $amount,
                        'effective_date' => $bill->transaction_date,
                        'bill_id' => $bill->id,
                        'bill_item_id' => $item->id,
                        'bill_split_id' => $split->id,
                        'settlement_id' => null,
                        'description' => $this->buildBillDescription($bill, $item, $split->debtor?->name),
                        'metadata' => json_encode([
                            'merchant' => $bill->merchant,
                            'bill_title' => $bill->title,
                            'item_name' => $item->name,
                        ]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            $bill->updateQuietly([
                'total_amount' => $totalAmount,
            ]);

            $bill->ledgerEntries()->where('entry_type', 'obligation')->delete();

            if ($entries !== []) {
                LedgerEntry::query()->insert($entries);
            }
        });
    }

    public function syncSettlement(Settlement $settlement): void
    {
        DB::transaction(function () use ($settlement): void {
            if ($settlement->from_user_id === $settlement->to_user_id) {
                throw ValidationException::withMessages([
                    'to_user_id' => 'Pelunasan harus melibatkan dua orang yang berbeda.',
                ]);
            }

            if ((int) $settlement->amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Nominal pelunasan harus lebih dari nol.',
                ]);
            }

            $settlement->loadMissing(['fromUser:id,name', 'toUser:id,name']);

            $settlement->ledgerEntries()->delete();

            LedgerEntry::query()->create([
                'entry_type' => 'settlement',
                'debtor_user_id' => $settlement->from_user_id,
                'creditor_user_id' => $settlement->to_user_id,
                'amount' => $settlement->amount,
                'effective_date' => $settlement->paid_at,
                'bill_id' => null,
                'bill_item_id' => null,
                'bill_split_id' => null,
                'settlement_id' => $settlement->id,
                'description' => "Pelunasan dari {$settlement->fromUser?->name} ke {$settlement->toUser?->name}",
                'metadata' => [
                    'notes' => $settlement->notes,
                ],
            ]);
        });
    }

    public function getDashboardSummary(User $user): array
    {
        $balances = $this->getCounterpartyBalances($user);

        $totalReceivable = (int) $balances->sum(fn (array $balance): int => max($balance['net_amount'], 0));
        $totalPayable = (int) $balances->sum(fn (array $balance): int => abs(min($balance['net_amount'], 0)));
        $history = $this->getHistory($user);

        return [
            'total_receivable' => $totalReceivable,
            'total_payable' => $totalPayable,
            'payables_widget' => $this->buildDashboardWidget($balances, $history, 'payable'),
            'receivables_widget' => $this->buildDashboardWidget($balances, $history, 'receivable'),
        ];
    }

    public function getCounterpartyBalances(User $user): Collection
    {
        $entries = $this->entriesForUser($user)
            ->get();

        $balances = [];

        foreach ($entries as $entry) {
            if ($entry->creditor_user_id === $user->id) {
                $counterpartyId = $entry->debtor_user_id;
                $delta = $entry->entry_type === 'obligation'
                    ? (int) $entry->amount
                    : -1 * (int) $entry->amount;
                $direction = 'receivable';
            } else {
                $counterpartyId = $entry->creditor_user_id;
                $delta = $entry->entry_type === 'obligation'
                    ? -1 * (int) $entry->amount
                    : (int) $entry->amount;
                $direction = 'payable';
            }

            if (! isset($balances[$counterpartyId])) {
                $balances[$counterpartyId] = [
                    'counterparty' => $entry->creditor_user_id === $user->id ? $entry->debtor : $entry->creditor,
                    'net_amount' => 0,
                    'raw_receivable' => 0,
                    'raw_payable' => 0,
                ];
            }

            $balances[$counterpartyId]['net_amount'] += $delta;

            if ($direction === 'receivable') {
                $balances[$counterpartyId]['raw_receivable'] += (int) $entry->amount;
            } else {
                $balances[$counterpartyId]['raw_payable'] += (int) $entry->amount;
            }
        }

        return collect($balances)
            ->map(function (array $balance): array {
                $balance['status'] = $balance['net_amount'] >= 0 ? 'receivable' : 'payable';

                return $balance;
            })
            ->sortByDesc(fn (array $balance): int => abs($balance['net_amount']))
            ->values();
    }

    public function getHistory(User $user, ?int $counterpartyId = null): Collection
    {
        return $this->entriesForUser($user, $counterpartyId)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get()
            ->map(function (LedgerEntry $entry) use ($user): array {
                $isReceivable = $entry->creditor_user_id === $user->id;
                $counterparty = $isReceivable ? $entry->debtor : $entry->creditor;
                $merchant = $entry->bill?->merchant;
                $title = $entry->bill?->title ?? $entry->description;

                return [
                    'id' => $entry->id,
                    'entry_type' => $entry->entry_type,
                    'direction' => $isReceivable ? 'receivable' : 'payable',
                    'counterparty' => $counterparty,
                    'counterparty_id' => $counterparty?->id,
                    'counterparty_name' => $counterparty?->name ?? 'Unknown',
                    'amount' => (int) $entry->amount,
                    'effective_date' => $entry->effective_date,
                    'label' => $entry->entry_type === 'settlement'
                        ? 'Pelunasan'
                        : ($isReceivable ? 'Piutang' : 'Hutang'),
                    'title' => $title,
                    'bill_id' => $entry->bill?->id,
                    'bill_title' => $entry->bill?->title,
                    'item_name' => $entry->item?->name,
                    'merchant' => $merchant,
                    'notes' => $entry->settlement?->notes ?? $entry->bill?->notes,
                    'receipt_image_path' => $entry->bill?->receipt_image_path,
                ];
            });
    }

    public function getCounterpartyOptions(User $user): array
    {
        return $this->getCounterpartyBalances($user)
            ->mapWithKeys(fn (array $balance): array => [
                $balance['counterparty']->id => $balance['counterparty']->name,
            ])
            ->all();
    }

    private function entriesForUser(User $user, ?int $counterpartyId = null)
    {
        $query = LedgerEntry::query()
            ->with([
                'bill:id,title,merchant,transaction_date,notes,receipt_image_path',
                'item:id,name',
                'settlement:id,notes',
                'debtor:id,name,avatar_path',
                'creditor:id,name,avatar_path',
            ])
            ->where(function ($query) use ($user): void {
                $query
                    ->where('debtor_user_id', $user->id)
                    ->orWhere('creditor_user_id', $user->id);
            });

        if (! $counterpartyId) {
            return $query;
        }

        return $query->where(function ($query) use ($user, $counterpartyId): void {
            $query
                ->where(function ($innerQuery) use ($user, $counterpartyId): void {
                    $innerQuery
                        ->where('debtor_user_id', $user->id)
                        ->where('creditor_user_id', $counterpartyId);
                })
                ->orWhere(function ($innerQuery) use ($user, $counterpartyId): void {
                    $innerQuery
                        ->where('creditor_user_id', $user->id)
                        ->where('debtor_user_id', $counterpartyId);
                });
        });
    }

    private function buildBillDescription(Bill $bill, $item, ?string $debtorName): string
    {
        $merchant = $bill->merchant ? " - {$bill->merchant}" : '';
        $debtor = $debtorName ? "{$debtorName} " : '';

        return trim("{$debtor}ikut bill {$bill->title}{$merchant} untuk item {$item->name}");
    }

    private function buildDashboardWidget(Collection $balances, Collection $history, string $direction): array
    {
        $groups = $balances
            ->filter(function (array $balance) use ($direction): bool {
                if ($direction === 'payable') {
                    return $balance['net_amount'] < 0;
                }

                return $balance['net_amount'] > 0;
            })
            ->map(function (array $balance) use ($history, $direction): array {
                $counterpartyId = $balance['counterparty']->id;
                $entries = $history
                    ->where('counterparty_id', $counterpartyId)
                    ->map(function (array $entry): array {
                        $signedAmount = match (true) {
                            $entry['entry_type'] === 'obligation' && $entry['direction'] === 'receivable' => $entry['amount'],
                            $entry['entry_type'] === 'obligation' && $entry['direction'] === 'payable' => -1 * $entry['amount'],
                            $entry['entry_type'] === 'settlement' && $entry['direction'] === 'receivable' => -1 * $entry['amount'],
                            default => $entry['amount'],
                        };

                        return [
                            ...$entry,
                            'signed_amount' => $signedAmount,
                            'is_offset' => $signedAmount < 0,
                            'display_name' => $entry['entry_type'] === 'settlement'
                                ? 'Pelunasan'
                                : ($entry['item_name'] ?: $entry['bill_title'] ?: $entry['title']),
                        ];
                    })
                    ->sortByDesc(fn (array $entry) => $entry['effective_date']->timestamp)
                    ->values();

                return [
                    'counterparty' => $balance['counterparty'],
                    'total_amount' => abs((int) $balance['net_amount']),
                    'entries' => $entries,
                ];
            })
            ->sortByDesc('total_amount')
            ->values();

        return [
            'total_amount' => (int) $groups->sum('total_amount'),
            'groups' => $groups,
        ];
    }
}
