<?php

namespace Mollsoft\LaravelMoneroModule\Commands;

use Illuminate\Console\Command;
use Mollsoft\LaravelMoneroModule\Facades\Monero;
use Mollsoft\LaravelMoneroModule\Models\MoneroWallet;

class MoneroViewKeyCommand extends Command
{
    protected $signature = 'monero:view-key {--wallet_id=1}';

    protected $description = 'Get private view key for a wallet';

    public function handle(): void
    {
        /** @var MoneroWallet $wallet */
        $wallet = Monero::getModelWallet()::findOrFail($this->option-('wallet_id'));

        Monero::generalAtomicLock($wallet, function () use ($wallet) {
            $api = $wallet->node->api();
            $api->openWallet($wallet->name, $wallet->password);

            $viewKey = $api->getViewKey();
            $spendKey = $api->getSpendKey();
            $address = $api->getAddress(0);
            $primaryAddress = $address['address'] ?? null;

            $this->info("Wallet: {$wallet->name} (ID: {$wallet->id})");
            $this->newLine();

            $this->table(
                ['Parameter', 'Value'],
                [
                    ['Primary Address', $primaryAddress],
                    ['Private View Key', $viewKey],
                    ['Private Spend Key', $spendKey],
                ]
            );

            $this->newLine();
            $this->warn('Private View Key можно передавать для проверки входящих платежей (decode outputs).');
            $this->warn('Private Spend Key НЕЛЬЗЯ передавать — с ним можно украсть средства!');
        });
    }
}
