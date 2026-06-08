<?php

namespace Mollsoft\LaravelMoneroModule\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Прокси-модель над таблицей crypto_transactions.
 *
 * Таблица monero_transactions удалена. Транзакции хранятся в crypto_transactions
 * (txid, time_at) + crypto_transaction_details (from, to, amount, amount_usd, fee, fee_usd).
 *
 * Направление переноса адреса в детали:
 *   - входящая транзакция (in)  -> crypto_transaction_details.to
 *   - исходящая транзакция (out) -> crypto_transaction_details.from
 */
class MoneroTransaction extends Model
{
    protected $fillable = [
        'network',
        'crypto_coin_id',
        'txid',
        'time_at',
    ];

    protected $casts = [
        'time_at' => 'datetime',
    ];

    protected static ?int $xmrCoinId = null;

    public function getTable(): string
    {
        if (!isset($this->table)) {
            $model = config('monero.crypto.transaction_model');
            $this->table = $model ? (new $model)->getTable() : 'monero_transactions';
        }

        return $this->table;
    }

    protected static function booted(): void
    {
        static::addGlobalScope('xmrNetwork', function (Builder $builder) {
            if (config('monero.crypto.transaction_model')) {
                $builder->where($builder->getModel()->getTable() . '.network', static::cryptoNetwork());
            }
        });
    }

    protected static function cryptoNetwork(): string
    {
        return config('monero.crypto.network', 'XMR');
    }

    protected static function xmrCoinId(): ?int
    {
        if (static::$xmrCoinId === null) {
            $code = config('monero.crypto.coin_code', 'XMR');
            static::$xmrCoinId = (int)(DB::table('crypto_coins')->where('code', $code)->value('id') ?? 0);
        }

        return static::$xmrCoinId ?: null;
    }

    public function detail(): HasOne
    {
        return $this->hasOne(config('monero.crypto.transaction_detail_model'), 'transaction_id');
    }

    protected function address(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->detail?->to ?? $this->detail?->from,
        );
    }

    protected function type(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->detail?->to ? 'in' : ($this->detail?->from ? 'out' : null),
        );
    }

    protected function amount(): Attribute
    {
        return Attribute::make(get: fn() => $this->detail?->amount);
    }

    protected function amountUsd(): Attribute
    {
        return Attribute::make(get: fn() => $this->detail?->amount_usd);
    }

    protected function fee(): Attribute
    {
        return Attribute::make(get: fn() => $this->detail?->fee);
    }

    protected function feeUsd(): Attribute
    {
        return Attribute::make(get: fn() => $this->detail?->fee_usd);
    }

    /**
     * Записать (создать/обновить) транзакцию в crypto_transactions + crypto_transaction_details.
     *
     * @param string $direction 'in' | 'out'
     */
    public static function record(
        string $direction,
        string $txid,
        ?string $address,
        $amount,
        $amountUsd,
        $fee,
        $feeUsd,
        $timeAt
    ): Model {
        $transactionModel = config('monero.crypto.transaction_model');
        $detailModel = config('monero.crypto.transaction_detail_model');

        $transaction = $transactionModel::updateOrCreate(
            [
                'network' => static::cryptoNetwork(),
                'txid' => $txid,
            ],
            [
                'crypto_coin_id' => static::xmrCoinId(),
                'time_at' => $timeAt,
            ]
        );

        $detail = $detailModel::firstOrNew(['transaction_id' => $transaction->id]);
        $isNew = !$detail->exists;

        if ($direction === 'out') {
            $detail->from = $address;
        } else {
            $detail->to = $address;
        }

        // Суммы исходящей транзакции отражают итог перевода и имеют приоритет.
        if ($isNew || $direction === 'out') {
            $detail->amount = $amount;
            $detail->amount_usd = $amountUsd;
            $detail->fee = $fee;
            $detail->fee_usd = $feeUsd;
        }

        if ($detail->amount === null) {
            $detail->amount = 0;
        }

        $detail->transaction_id = $transaction->id;
        $detail->save();

        return $transaction;
    }
}
