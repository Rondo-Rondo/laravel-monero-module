<?php

namespace Mollsoft\LaravelMoneroModule\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Facades\DB;
use Mollsoft\LaravelMoneroModule\Casts\BigDecimalCast;
use Mollsoft\LaravelMoneroModule\Facades\Monero;

class MoneroAddress extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'wallet_id',
        'crypto_address_id',
        'account_id',
        'address',
        'address_index',
        'title',
        'balance',
        'unlocked_balance',
        'sync_at',
        'available',
    ];

    protected $casts = [
        'balance' => BigDecimalCast::class,
        'available' => 'boolean',
    ];

    protected $with = ['cryptoAddress', 'xmrBalance'];

    protected $appends = [
        'address',
        'address_index',
        'account_id',
        'unlocked_balance',
        'sync_at',
        'created_at',
        'updated_at',
    ];

    protected static ?int $xmrCoinId = null;

    protected array $pendingCryptoAddress = [];

    protected ?int $pendingCryptoWalletId = null;

    protected mixed $pendingUnlockedBalance = null;

    protected bool $hasPendingUnlockedBalance = false;

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            $model->syncCryptoAddressOnSave();
        });

        static::saved(function (self $model) {
            $model->syncUnlockedBalanceAfterSave();
        });
    }

    protected static function cryptoAddressModel(): string
    {
        return config('monero.crypto.address_model');
    }

    protected static function cryptoBalanceModel(): string
    {
        return config('monero.crypto.balance_model');
    }

    protected static function xmrCoinId(): ?int
    {
        if (static::$xmrCoinId === null) {
            $code = config('monero.crypto.coin_code', 'XMR');
            static::$xmrCoinId = (int)(DB::table('crypto_coins')->where('code', $code)->value('id') ?? 0);
        }

        return static::$xmrCoinId ?: null;
    }

    public function cryptoAddress(): BelongsTo
    {
        return $this->belongsTo(static::cryptoAddressModel(), 'crypto_address_id');
    }

    public function xmrBalance(): HasOneThrough
    {
        return $this->hasOneThrough(
            static::cryptoBalanceModel(),
            static::cryptoAddressModel(),
            'id',
            'crypto_address_id',
            'crypto_address_id',
            'id'
        )->where('crypto_address_balances.crypto_coin_id', static::xmrCoinId());
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Monero::getModelWallet(), 'wallet_id');
    }

    public function account(): HasOneThrough
    {
        return $this->hasOneThrough(
            Monero::getModelAccount(),
            static::cryptoAddressModel(),
            'id',
            'crypto_wallet_id',
            'crypto_address_id',
            'wallet_id'
        );
    }

    public function deposits(): HasManyThrough
    {
        return $this->hasManyThrough(
            Monero::getModelDeposit(),
            config('monero.crypto.deposit_model'),
            'address_id',
            'crypto_deposit_id',
            'crypto_address_id',
            'id'
        );
    }

    /**
     * Создать депозит для текущего адреса (запись в crypto_deposits через прокси).
     */
    public function createDeposit(array $attributes): Model
    {
        $depositModel = Monero::getModelDeposit();

        return $depositModel::create(array_merge($attributes, [
            'wallet_id' => $attributes['wallet_id'] ?? $this->wallet_id,
            'account_id' => $this->account_id,
            'address_id' => $this->crypto_address_id,
        ]));
    }

    public function updateOrCreateDeposit(string $txid, array $attributes): Model
    {
        $existing = $this->findDepositByTxid($txid);

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        return $this->createDeposit(array_merge(['txid' => $txid], $attributes));
    }

    public function findDepositByTxid(string $txid): ?Model
    {
        return $this->deposits()
            ->where('crypto_deposits.txid', $txid)
            ->first();
    }

    protected function address(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->cryptoAddress?->address,
            set: function ($value) {
                $this->pendingCryptoAddress['address'] = $value;
                return [];
            }
        );
    }

    protected function addressIndex(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->cryptoAddress?->index,
            set: function ($value) {
                $this->pendingCryptoAddress['index'] = $value;
                return [];
            }
        );
    }

    protected function syncAt(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->cryptoAddress?->sync_at,
            set: function ($value) {
                $this->pendingCryptoAddress['sync_at'] = $value;
                return [];
            }
        );
    }

    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->cryptoAddress?->created_at,
            set: function ($value) {
                $this->pendingCryptoAddress['created_at'] = $value;
                return [];
            }
        );
    }

    protected function updatedAt(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->cryptoAddress?->updated_at,
            set: function ($value) {
                $this->pendingCryptoAddress['updated_at'] = $value;
                return [];
            }
        );
    }

    protected function title(): Attribute
    {
        return Attribute::make(
            get: fn() => null,
            set: fn($value) => [],
        );
    }

    protected function accountId(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->cryptoAddress?->wallet_id,
            set: function ($value) {
                $this->pendingCryptoWalletId = $value !== null ? (int)$value : null;
                return [];
            }
        );
    }

    protected function unlockedBalance(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->resolveUnlockedBalance(),
            set: function ($value) {
                $this->pendingUnlockedBalance = $value;
                $this->hasPendingUnlockedBalance = true;
                return [];
            }
        );
    }

    protected function resolveUnlockedBalance(): ?BigDecimal
    {
        $balance = $this->relationLoaded('xmrBalance')
            ? $this->getRelation('xmrBalance')
            : $this->xmrBalance;

        return $balance?->balance;
    }

    protected function syncCryptoAddressOnSave(): void
    {
        $addressModel = static::cryptoAddressModel();

        if ($this->crypto_address_id) {
            if (!empty($this->pendingCryptoAddress)) {
                $this->cryptoAddress?->update($this->pendingCryptoAddress);
            }
        } else {
            $walletId = $this->pendingCryptoWalletId;

            if (!$walletId) {
                throw new \RuntimeException('Cannot create MoneroAddress without related crypto wallet id (account_id).');
            }

            $address = $this->pendingCryptoAddress['address'] ?? null;
            $cryptoAddress = null;

            if ($address !== null) {
                $cryptoAddress = $addressModel::query()
                    ->where('wallet_id', $walletId)
                    ->where('address', $address)
                    ->first();
            }

            if ($cryptoAddress) {
                if (!empty($this->pendingCryptoAddress)) {
                    $cryptoAddress->update($this->pendingCryptoAddress);
                }
            } else {
                $cryptoAddress = $addressModel::create(array_merge(
                    $this->pendingCryptoAddress,
                    ['wallet_id' => $walletId]
                ));
            }

            $this->crypto_address_id = $cryptoAddress->id;
            $this->setRelation('cryptoAddress', $cryptoAddress);
        }

        $this->pendingCryptoAddress = [];
    }

    protected function syncUnlockedBalanceAfterSave(): void
    {
        if (!$this->hasPendingUnlockedBalance) {
            return;
        }

        $coinId = static::xmrCoinId();
        if (!$coinId || !$this->crypto_address_id) {
            $this->hasPendingUnlockedBalance = false;
            $this->pendingUnlockedBalance = null;
            return;
        }

        $value = $this->pendingUnlockedBalance;
        $balanceModel = static::cryptoBalanceModel();

        $balanceModel::updateOrCreate(
            [
                'crypto_address_id' => $this->crypto_address_id,
                'crypto_coin_id' => $coinId,
            ],
            [
                'balance' => $value !== null ? (string)$value : null,
            ]
        );

        $this->hasPendingUnlockedBalance = false;
        $this->pendingUnlockedBalance = null;
        $this->unsetRelation('xmrBalance');
    }
}
