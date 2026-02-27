<?php

namespace Mollsoft\LaravelMoneroModule\Services\Sync;

use Brick\Math\BigDecimal;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Mollsoft\LaravelMoneroModule\Api\Api;
use Mollsoft\LaravelMoneroModule\Facades\Monero;
use Mollsoft\LaravelMoneroModule\Models\MoneroAccount;
use Mollsoft\LaravelMoneroModule\Models\MoneroDeposit;
use Mollsoft\LaravelMoneroModule\Models\MoneroNode;
use Mollsoft\LaravelMoneroModule\Models\MoneroWallet;
use Mollsoft\LaravelMoneroModule\Services\BaseConsole;
use Mollsoft\LaravelMoneroModule\WebhookHandlers\WebhookHandlerInterface;

class WalletSync extends BaseConsole
{
    protected MoneroWallet $wallet;
    protected ?MoneroNode $node;
    protected ?Api $api;
    protected ?WebhookHandlerInterface $webhookHandler;
    /** @var MoneroDeposit[] */
    protected array $webhooks = [];

    public function __construct(MoneroWallet $wallet, ?MoneroNode $node = null, ?Api $api = null)
    {
        $this->wallet = $wallet;
        $this->node = $node ?? $wallet->node;
        $this->api = $api;

        $model = Monero::getModelWebhook();
        $this->webhookHandler = $model ? App::make($model) : null;
    }

    public function run(): void
    {
        parent::run();

        $this->log("Начинаем синхронизацию кошелька {$this->wallet->name}...");

        try {
            Monero::walletAtomicLock($this->wallet, function () {
                $this
                    ->apiConnect()
                    ->openWallet()
                    ->getBalances()
                    ->incomingTransfers()
                    ->runWebhooks();
            }, 5);
        } catch (LockTimeoutException $e) {
            $this->log("Ошибка: кошелек сейчас заблокирован другим процессом.", "error");
            return;
        } catch (\Exception $e) {
            $this->log("Ошибка: {$e->getMessage()}", "error");
            return;
        }

        $this->log("Кошелек {$this->wallet->name} успешно синхронизирован!");
    }

    protected function apiConnect(): static
    {
        if (!$this->api) {
            $this->log("Подключаемся к Node по API...");
            $this->api = $this->node->api();
            $this->log("Подключение к API успешно выполнено!");
        }

        $this->log("Запрашиваем высоту синхронизации ноды...");
        $daemonHeight = $this->api->getDaemonHeight();
        $this->log("Результат: $daemonHeight");

        $this->wallet->update(['daemon_height' => $daemonHeight]);

        return $this;
    }

    protected function openWallet(): self
    {
        $this->log("Открываем кошелек {$this->wallet->name}...");
        $this->api->openWallet($this->wallet->name, $this->wallet->password);
        $this->log('Кошелек успешно открыт!');

        $this->log("Запрашиваем высоту синхронизации кошелька...");
        $walletHeight = $this->api->getHeight();
        $this->log("Результат: $walletHeight");

        $this->wallet->update(['wallet_height' => $walletHeight]);

        return $this;
    }

    protected function getBalances(): self
    {
        $this->log('Запрашиваем список счетов через метод get_accounts...');
        $getAccounts = $this->api->getAccounts();
        $this->log('Успешно: '.json_encode($getAccounts));

        $balance = BigDecimal::of($getAccounts['total_balance'] ?: '0')->dividedBy(pow(10, 12), 12);
        $unlockedBalance = BigDecimal::of($getAccounts['total_unlocked_balance'] ?: '0')->dividedBy(pow(10, 12), 12);

        $this->wallet->update([
            'sync_at' => Date::now(),
            'balance' => $balance,
            'unlocked_balance' => $unlockedBalance,
        ]);

        foreach ($getAccounts['subaddress_accounts'] ?? [] as $item) {
            $balance = (BigDecimal::of($item['balance'] ?: '0'))->dividedBy(pow(10, 12), 12);
            $unlockedBalance = (BigDecimal::of($item['unlocked_balance'] ?: '0'))->dividedBy(pow(10, 12), 12);

            $account = $this->wallet
                ->accounts()
                ->updateOrCreate([
                    'base_address' => $item['base_address'],
                ], [
                    'account_index' => $item['account_index'],
                    'balance' => $balance,
                    'unlocked_balance' => $unlockedBalance,
                    'sync_at' => now(),
                ]);

        }

        $this->wallet
            ->addresses()
            ->update([
                'balance' => 0,
                'unlocked_balance' => 0,
                'sync_at' => now(),
            ]);

        $this->log("Запрашиваем все балансы методом get_balance ...");
        $getBalance = $this->api->getAllBalance();
        $this->log('Успех: '.json_encode($getBalance));
        foreach( $getBalance['per_subaddress'] ?? [] as $item ) {
            $isOK = $this->wallet
                ->addresses()
                ->where('address', $item['address'])
                ->update([
                    'balance' => (BigDecimal::of($item['balance'] ?: '0'))->dividedBy(pow(10, 12), 12),
                    'unlocked_balance' => (BigDecimal::of($item['unlocked_balance'] ?: '0'))->dividedBy(pow(10, 12), 12),
                    'sync_at' => now(),
                ]);
            if( $isOK ) {
                $this->log('Баланс по адресу '.$item['address'].' успешно обновлен!', 'success');
            }
        }

        return $this;
    }

    /**
     * @throws \Exception
     */
    protected function incomingTransfers(): self
    {
        $this->log("Запрашиваем историю входящих переводов...");
        $getTransfers = $this->api->request(
            'get_transfers',
            [
                'in' => true,
                'out' => true,
//                'pending' => true,
//                'pool' => true,
                'all_accounts' => true
            ]
        );
        $this->log('История получена: '.json_encode($getTransfers));

//        $transfers = array_merge($getTransfers['pool'] ?? [], $getTransfers['in'] ?? []);
        $transfers = array_merge([], $getTransfers['in'] ?? []);

        $rows = [];

        foreach ($transfers as $item) {
            $amount = (BigDecimal::of($item['amount'] ?: '0'))->dividedBy(pow(10, 12), 12);
            $fee = (BigDecimal::of($item['fee'] ?: '0'))->dividedBy(pow(10, 12), 12);

            $address = $this->wallet
                ->addresses()
                ->whereAddress($item['address'])
                ->first();

            if (!$address) {
                $account = $this->wallet
                    ->accounts()
                    ->where('account_index', $item['subaddr_index']['major'] ?? 0)
                    ->first();

                if ($account) {
                    $address = $this->wallet->addresses()->create([
                        'account_id' => $account->id,
                        'address' => $item['address'],
                        'address_index' => $item['subaddr_index']['minor'] ?? 0,
                    ]);
                    $this->log("Создан новый адрес в БД: {$item['address']} (index: $address->address_index)");
                }
            }

            if (!$address) {
                $this->log("Пропускаем транзакцию - адрес не найден: {$item['address']}", 'warning');
                continue;
            }

            $deposit = $address->deposits()->updateOrCreate([
                'txid' => $item['txid']
            ], [
                'wallet_id' => $this->wallet->id,
                'account_id' => $address->account_id,
                'amount' => $amount,
                'block_height' => ($item['height'] ?? 0) ?: null,
                'confirmations' => $item['confirmations'] ?? 0,
                'time_at' => Date::createFromTimestamp($item['timestamp']),
            ]);

            if ($deposit?->wasRecentlyCreated) {
                $this->webhooks[] = $deposit;
            }

            $rows[] = [
                'txid' => $item['txid'],
                'address' => $item['address'],
                'type' => $item['type'],
                'amount' => (string)$amount,
                'amount_usd' => (string)$this->convertToUsd($amount),
                'fee' => (string)$fee,
                'fee_usd' => (string)$this->convertToUsd($fee),
                'block_height' => ($item['height'] ?? 0) ?: null,
                'confirmations' => $item['confirmations'] ?? 0,
                'time_at' => Date::createFromTimestamp($item['timestamp']),
                'data' => json_encode($item),
                'updated_at' => now(),
                'created_at' => now(),
            ];
        }

        foreach( $getTransfers['out'] ?? [] as $item ) {
            $amount = (BigDecimal::of($item['amount'] ?: '0'))->dividedBy(pow(10, 12), 12);
            $fee = (BigDecimal::of($item['fee'] ?: '0'))->dividedBy(pow(10, 12), 12);

            $rows[] = [
                'txid' => $item['txid'],
                'address' => $item['address'],
                'type' => $item['type'],
                'amount' => (string)$amount,
                'amount_usd' => (string)$this->convertToUsd($amount),
                'fee' => (string)$fee,
                'fee_usd' => (string)$this->convertToUsd($fee),
                'block_height' => ($item['height'] ?? 0) ?: null,
                'confirmations' => $item['confirmations'] ?? 0,
                'time_at' => Date::createFromTimestamp($item['timestamp']),
                'data' => json_encode($item),
                'updated_at' => now(),
                'created_at' => now(),
            ];
        }

        if( !empty($rows) ) {
            Monero::getModelTransaction()::upsert(
                $rows,
                ['txid', 'address'],
                ['type', 'amount', 'fee', 'block_height', 'confirmations', 'time_at', 'data', 'updated_at']
            );
        }

        return $this;
    }

    protected function convertToUsd(BigDecimal $amount): BigDecimal
    {
        $serviceClass = config('monero.exchange_rate_service');

        if (!$serviceClass) {
            return BigDecimal::zero();
        }

        try {
            $service = app($serviceClass);
            return $service->convertToUsd('XMR', $amount);
        } catch (\Exception $e) {
            Log::warning('Monero: Failed to convert to USD', [
                'amount' => (string)$amount,
                'error' => $e->getMessage(),
            ]);
            return BigDecimal::zero();
        }
    }

    protected function runWebhooks(): self
    {
        if ($this->webhookHandler) {
            foreach ($this->webhooks as $item) {
                try {
                    $this->log('Запускаем Webhook на новый Deposit ID#'.$item->id.'...');
                    $this->webhookHandler->handle($item);
                    $this->log('Webhook успешно обработан!');
                } catch (\Exception $e) {
                    $this->log('Ошибка обработки Webhook: '.$e->getMessage());
                    Log::error('Monero WebHook for deposit '.$item->id.' - '.$e->getMessage());
                }
            }
        }

        return $this;
    }
}