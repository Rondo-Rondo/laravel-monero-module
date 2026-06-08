<?php

namespace Mollsoft\LaravelMoneroModule\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Mollsoft\LaravelMoneroModule\Casts\BigDecimalCast;
use Mollsoft\LaravelMoneroModule\Facades\Monero;

class MoneroAccount extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'wallet_id',
        'crypto_wallet_id',
        'name',
        'base_address',
        'title',
        'account_index',
        'balance',
        'unlocked_balance',
        'sync_at',
        'available',
    ];

    protected $casts = [
        'account_index' => 'integer',
        'balance' => BigDecimalCast::class,
        'unlocked_balance' => BigDecimalCast::class,
        'sync_at' => 'datetime',
        'available' => 'boolean',
    ];

    protected $with = ['cryptoWallet'];

    protected $appends = ['name', 'title', 'created_at', 'updated_at'];

    protected array $pendingCryptoWallet = [];

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            $model->syncCryptoWalletOnSave();
        });
    }

    protected static function cryptoWalletModel(): string
    {
        return config('monero.crypto.wallet_model');
    }

    protected static function cryptoNetwork(): string
    {
        return config('monero.crypto.network', 'XMR');
    }

    public function cryptoWallet(): BelongsTo
    {
        return $this->belongsTo(static::cryptoWalletModel(), 'crypto_wallet_id');
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->cryptoWallet?->name,
            set: function ($value) {
                $this->pendingCryptoWallet['name'] = $value;
                return [];
            }
        );
    }

    protected function title(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->cryptoWallet?->title,
            set: function ($value) {
                $this->pendingCryptoWallet['title'] = $value;
                return [];
            }
        );
    }

    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->cryptoWallet?->created_at,
            set: function ($value) {
                $this->pendingCryptoWallet['created_at'] = $value;
                return [];
            }
        );
    }

    protected function updatedAt(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->cryptoWallet?->updated_at,
            set: function ($value) {
                $this->pendingCryptoWallet['updated_at'] = $value;
                return [];
            }
        );
    }

    protected function syncCryptoWalletOnSave(): void
    {
        if (empty($this->pendingCryptoWallet) && $this->crypto_wallet_id) {
            return;
        }

        $walletModel = static::cryptoWalletModel();

        if (!$this->crypto_wallet_id) {
            $cryptoWallet = $walletModel::create(array_merge(
                [
                    'network' => static::cryptoNetwork(),
                    'name' => null,
                    'title' => null,
                ],
                $this->pendingCryptoWallet
            ));

            $this->crypto_wallet_id = $cryptoWallet->id;
            $this->setRelation('cryptoWallet', $cryptoWallet);
        } elseif (!empty($this->pendingCryptoWallet)) {
            $this->cryptoWallet?->update($this->pendingCryptoWallet);
        }

        $this->pendingCryptoWallet = [];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Monero::getModelWallet(), 'wallet_id');
    }

    public function addresses(): HasManyThrough
    {
        return $this->hasManyThrough(
            Monero::getModelAddress(),
            config('monero.crypto.address_model'),
            'wallet_id',
            'crypto_address_id',
            'crypto_wallet_id',
            'id'
        );
    }

    public function getPrimaryAddressAttribute(): ?MoneroAddress
    {
        return $this->addresses()
            ->orderBy('crypto_addresses.index')
            ->first();
    }

    public function deposits(): HasManyThrough
    {
        return $this->hasManyThrough(
            Monero::getModelDeposit(),
            config('monero.crypto.deposit_model'),
            'wallet_id',
            'crypto_deposit_id',
            'crypto_wallet_id',
            'id'
        );
    }

    public function findAddressByIndex(int $index): ?MoneroAddress
    {
        return $this->addresses()
            ->where('crypto_addresses.index', $index)
            ->first();
    }

    public function createAddress(array $attributes): MoneroAddress
    {
        $addressModel = Monero::getModelAddress();

        return $addressModel::create(array_merge($attributes, [
            'wallet_id' => $attributes['wallet_id'] ?? $this->wallet_id,
            'account_id' => $this->crypto_wallet_id,
        ]));
    }
}
