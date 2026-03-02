<?php

namespace Mollsoft\LaravelMoneroModule\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mollsoft\LaravelMoneroModule\Casts\BigDecimalCast;
use Mollsoft\LaravelMoneroModule\Facades\Monero;

class MoneroTransaction extends Model
{
    protected $fillable = [
        'txid',
        'address',
        'type',
        'amount',
        'amount_usd',
        'fee',
        'fee_usd',
        'time_at',
    ];

    protected $casts = [
        'amount' => BigDecimalCast::class,
        'amount_usd' => BigDecimalCast::class,
        'fee' => BigDecimalCast::class,
        'fee_usd' => BigDecimalCast::class,
        'time_at' => 'datetime',
    ];

    public function addresses(): HasMany
    {
        return $this->hasMany(Monero::getModelAddress(), 'address', 'address');
    }
}
