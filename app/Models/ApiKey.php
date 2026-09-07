<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKey extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'environment',
        'key_prefix',
        'secret_key',
        'revoked_at',
        'last_used_at',
    ];

    /**
     * Get the merchant that owns the API key.
     */

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
