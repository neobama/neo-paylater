<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillItem extends Model
{
    protected $fillable = [
        'bill_id',
        'name',
        'quantity',
        'unit_price',
        'line_subtotal',
        'total_amount',
        'source',
        'sort_order',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function splits(): HasMany
    {
        return $this->hasMany(BillSplit::class)->orderBy('sort_order');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
