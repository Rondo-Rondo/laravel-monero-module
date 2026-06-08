<?php

namespace Mollsoft\LaravelMoneroModule\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Facades\DB;
use Mollsoft\LaravelMoneroModule\Facades\Monero;

/**
 * Прокси-модель над таблицей crypto_deposits.
 *
 * Собственная таблица monero_deposits хранит только сетевые поля Monero
 * (wallet_id, block_height, status) и ссылку crypto_deposit_id.
 * Все остальные поля (txid, amount, confirmations, processed, time_at,
 * created_at, updated_at, account_id, address_id) проксируются в crypto_deposits.
 */
class MoneroDeposit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'wallet_id',
        'crypto_deposit_id',
        'block_height',
        'status',
        'account_id',
        'address_id',
        'txid',
        'amount',
        'confirmations',
        'processed',
        'time_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'block_height' => 'integer',
    ];

    protected $with = ['cryptoDeposit'];

    protected $appends = [
        'account_id',
        'address_id',
        'txid',
        'amount',
        'confirmations',
        'processed',
        'time_at',
        'created_at',
        'updated_at',
    ];

    protected static ?int $xmrCoinId = null;

    protected array $pendingCryptoDeposit = [];

    protected ?int $pendingCryptoWalletId = null;

    protected ?int $pendingCryptoAddressId = null;

    protected bool $hasPendingCryptoWalletId = false;

    protected bool $hasPendingCryptoAddressId = false;

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            $model->syncCryptoDepositOnSave();
        });
    }

    protected static function cryptoDepositModel(): string
    {
        return config('monero.crypto.deposit_model');
    }

    protected static function cryptoNetwork(): string
    {
        return config('monero.crypto.network', 'XMR');
    }

    protected static function requiredConfirmations(): int
    {
        return (int)config('monero.crypto.required_confirmations', 1);
    }

    protected static function xmrCoinId(): ?int
    {
        if (static::$xmrCoinId === null) {
            $code = config('monero.crypto.coin_code', 'XMR');
            static::$xmrCoinId = (int)(DB::table('crypto_coins')->where('code', $code)->value('id') ?? 0);
        }

        return static::$xmrCoinId ?: null;
    }

    public function cryptoDeposit(): BelongsTo
    {
        return $this->belongsTo(static::cryptoDepositModel(), 'crypto_deposit_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Monero::getModelWallet(), 'wallet_id');
    }

    public function address(): HasOneThrough
    {
        return $this->hasOneThrough(
            Monero::getModelAddress(),
            static::cryptoDepositModel(),
            'id',
            'crypto_address_id',
            'crypto_deposit_id',
            'address_id'
        );
    }

    public function account(): HasOneThrough
    {
        return $this->hasOneThrough(
            Monero::getModelAccount(),
            static::cryptoDepositModel(),
            'id',
            'crypto_wallet_id',
            'crypto_deposit_id',
            'wallet_id'
        );
    }

    protected function txid(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->cryptoDeposit?->txid,
            set: function ($value) {
                $this->pendingCryptoDeposit['txid'] = $value;
                return [];
            }
        );
    }

    protected function amount(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->cryptoDeposit?->amount,
            set: function ($value) {
                $this->pendingCryptoDeposit['amount'] = $value;
                return [];
            }
        );
    }

    protected function confirmations(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->cryptoDeposit?->confirmations,
            set: function ($value) {
                $this->pendingCryptoDeposit['confirmations'] = $value;
                return [];
            }
        );
    }

    protected function processed(): Attribute
    {
        return Attribute::make(
            get: fn() => (bool)$this->cryptoDeposit?->processed,
            set: function ($value) {
                $this->pendingCryptoDeposit['processed'] = $value;
                return [];
            }
        );
    }

    protected function timeAt(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->cryptoDeposit?->time_at,
            set: function ($value) {
                $this->pendingCryptoDeposit['time_at'] = $value;
                return [];
            }
        );
    }

    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->cryptoDeposit?->created_at,
            set: function ($value) {
                $this->pendingCryptoDeposit['created_at'] = $value;
                return [];
            }
        );
    }

    protected function updatedAt(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->cryptoDeposit?->updated_at,
            set: function ($value) {
                $this->pendingCryptoDeposit['updated_at'] = $value;
                return [];
            }
        );
    }

    protected function accountId(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->cryptoDeposit?->wallet_id,
            set: function ($value) {
                $this->pendingCryptoWalletId = $value !== null ? (int)$value : null;
                $this->hasPendingCryptoWalletId = true;
                return [];
            }
        );
    }

    protected function addressId(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->cryptoDeposit?->address_id,
            set: function ($value) {
                $this->pendingCryptoAddressId = $value !== null ? (int)$value : null;
                $this->hasPendingCryptoAddressId = true;
                return [];
            }
        );
    }

    protected function syncCryptoDepositOnSave(): void
    {
        if (empty($this->pendingCryptoDeposit)
            && !$this->hasPendingCryptoWalletId
            && !$this->hasPendingCryptoAddressId
            && $this->crypto_deposit_id
        ) {
            return;
        }

        $depositModel = static::cryptoDepositModel();

        if ($this->crypto_deposit_id) {
            $payload = $this->pendingCryptoDeposit;

            if ($this->hasPendingCryptoWalletId) {
                $payload['wallet_id'] = $this->pendingCryptoWalletId;
            }
            if ($this->hasPendingCryptoAddressId) {
                $payload['address_id'] = $this->pendingCryptoAddressId;
            }

            if (!empty($payload)) {
                $this->cryptoDeposit?->update($payload);
            }
        } else {
            $walletId = $this->pendingCryptoWalletId;
            $addressId = $this->pendingCryptoAddressId;

            if (!$walletId || !$addressId) {
                throw new \RuntimeException('Cannot create MoneroDeposit without related crypto wallet/address id.');
            }

            $txid = $this->pendingCryptoDeposit['txid'] ?? null;

            $cryptoDeposit = null;
            if ($txid !== null) {
                $cryptoDeposit = $depositModel::query()
                    ->where('txid', $txid)
                    ->where('address_id', $addressId)
                    ->first();
            }

            if ($cryptoDeposit) {
                if (!empty($this->pendingCryptoDeposit)) {
                    $cryptoDeposit->update($this->pendingCryptoDeposit);
                }
            } else {
                $vout = 0;
                if ($txid !== null) {
                    $maxVout = $depositModel::query()->where('txid', $txid)->max('vout');
                    $vout = $maxVout === null ? 0 : ((int)$maxVout + 1);
                }

                $cryptoDeposit = $depositModel::create(array_merge(
                    [
                        'vout' => $vout,
                        'required_confirmations' => static::requiredConfirmations(),
                        'crypto_coin_id' => static::xmrCoinId(),
                    ],
                    $this->pendingCryptoDeposit,
                    [
                        'wallet_id' => $walletId,
                        'address_id' => $addressId,
                    ]
                ));
            }

            $this->crypto_deposit_id = $cryptoDeposit->id;
            $this->setRelation('cryptoDeposit', $cryptoDeposit);
        }

        $this->pendingCryptoDeposit = [];
        $this->pendingCryptoWalletId = null;
        $this->pendingCryptoAddressId = null;
        $this->hasPendingCryptoWalletId = false;
        $this->hasPendingCryptoAddressId = false;
    }
}
