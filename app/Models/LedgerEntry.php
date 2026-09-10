<?php

namespace App\Models;

use App\Enums\LedgerEntryDirection;
use App\Enums\LedgerEntryType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'wallet_id',
        'transaction_id',
        'type',
        'direction',
        'amount',
        'currency',
        'balance_before',
        'balance_after',
        'description',
        'reference',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'balance_before' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'type' => LedgerEntryType::class,
            'direction' => LedgerEntryDirection::class,
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
