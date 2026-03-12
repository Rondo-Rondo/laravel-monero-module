<?php

namespace Mollsoft\LaravelMoneroModule\Commands;

use Illuminate\Console\Command;
use Mollsoft\LaravelMoneroModule\Facades\Monero;
use Mollsoft\LaravelMoneroModule\Models\MoneroWallet;

class MoneroTxKeyCommand extends Command
{
    protected $signature = 'monero:tx-key {txid} {--wallet_id=1}';

    protected $description = 'Get transaction private key (tx_key) for a sent transaction';

    public function handle(): void
    {
        /** @var MoneroWallet $wallet */
        $wallet = Monero::getModelWallet()::findOrFail($this->option('wallet_id'));
        $txid = $this->argument('txid');

        Monero::generalAtomicLock($wallet, function () use ($wallet, $txid) {
            $api = $wallet->node->api();
            $api->openWallet($wallet->name, $wallet->password);

            try {
                $txKey = $api->getTxKey($txid);
            } catch (\Exception $e) {
                $this->error("Ошибка: {$e->getMessage()}");
                $this->line('Возможные причины:');
                $this->line('  - txid не найден в этом кошельке');
                $this->line('  - транзакция была входящей, а не исходящей (tx_key есть только у отправителя)');
                $this->line('  - кошелек был восстановлен из seed (tx_key теряются при восстановлении)');
                return;
            }

            $this->info("Wallet: {$wallet->name} (ID: {$wallet->id})");
            $this->newLine();

            $this->table(
                ['Parameter', 'Value'],
                [
                    ['Transaction ID', $txid],
                    ['Tx Private Key', $txKey],
                ]
            );

            $this->newLine();
            $this->line('Этот ключ можно передать получателю для подтверждения отправки.');
            $this->line('Получатель может проверить через: php artisan monero:check-tx-key');
            $this->line("Или на эксплорере: https://monerohash.com/explorer/search?value={$txid} → Prove sending");
        });
    }
}
