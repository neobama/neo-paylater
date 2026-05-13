<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    protected $fillable = [
        'entry_type',
        'debtor_user_id',
        'creditor_user_id',
        'amount',
        'effective_date',
        'bill_id',
        'bill_item_id',
        'bill_split_id',
        'settlement_id',
        'description',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function debtor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'debtor_user_id');
    }

    public function creditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creditor_user_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(BillItem::class, 'bill_item_id');
    }

    public function split(): BelongsTo
    {
        return $this->belongsTo(BillSplit::class, 'bill_split_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }
}
