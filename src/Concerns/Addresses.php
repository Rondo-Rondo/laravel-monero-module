<?php

namespace Mollsoft\LaravelMoneroModule\Concerns;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Mollsoft\LaravelMoneroModule\Facades\Monero;
use Mollsoft\LaravelMoneroModule\Models\MoneroAccount;
use Mollsoft\LaravelMoneroModule\Models\MoneroAddress;
use Mollsoft\LaravelMoneroModule\Models\MoneroNode;

trait Addresses
{
//    public function createAddress(MoneroAccount $account, ?string $title = null): MoneroAddress
//    {
//        return Monero::generalAtomicLock($account->wallet, function () use ($account, $title) {
//            $wallet = $account->wallet;
//            $api = $wallet->node->api();
//
////            if( !$wallet->node->isLocal() ) {
//            $api->openWallet($wallet->name, $wallet->password);
////            }
//
//            $createAddress = $api->createAddress($account->account_index);
//
//            return $account->addresses()->create([
//                'wallet_id' => $wallet->id,
//                'address' => $createAddress['address'],
//                'address_index' => $createAddress['address_index'],
//                'title' => $title,
//            ]);
//        });
//    }

    public function createAddress(MoneroAccount $account, ?int $index = null, ?string $title = null): MoneroAddress
    {
        return Monero::generalAtomicLock($account->wallet, function () use ($account, $index, $title) {
            $wallet = $account->wallet;
            $api = $wallet->node->api();
            $api->openWallet($wallet->name, $wallet->password);

            if ($index !== null) {
                $existing = $account->addresses()->where('address_index', $index)->first();
                if ($existing) {
                    return $existing;
                }

                // Попробовать получить из wallet
                $result = $api->getAddressByIndex($account->account_index, [$index]);
                if ($result !== null && !empty($result['addresses'][0])) {
                    return $account->addresses()->updateOrCreate(
                        [
                            'account_id' => $account->id,
                            'address_index' => $result['addresses'][0]['address_index'],
                        ],
                        [
                            'wallet_id' => $wallet->id,
                            'address' => $result['addresses'][0]['address'],
                            'title' => $title,
                        ]
                    );
                }

                $addressInfo = $api->getAddress($account->account_index);
                $currentMaxIndex = count($addressInfo['addresses']) - 1;

                while ($currentMaxIndex < $index) {
                    $created = $api->createAddress($account->account_index);
                    $currentMaxIndex = $created['address_index'];
                }

                $result = $api->getAddressByIndex($account->account_index, [$index]);
                return $account->addresses()->updateOrCreate(
                    [
                        'account_id' => $account->id,
                        'address_index' => $index,
                    ],
                    [
                        'wallet_id' => $wallet->id,
                        'address' => $result['addresses'][0]['address'],
                        'title' => $title,
                    ]
                );
            }

            $createAddress = $api->createAddress($account->account_index);
            return $account->addresses()->updateOrCreate(
                [
                    'account_id' => $account->id,
                    'address_index' => $createAddress['address_index'],
                ],
                [
                    'wallet_id' => $wallet->id,
                    'address' => $createAddress['address'],
                    'title' => $title,
                ]
            );
        });
    }

    public function validateAddress(MoneroNode $node, string $address): bool
    {
        $api = $node->api();

        $details = $api->validateAddress($address);

        return (bool)$details['valid'];
    }

    public function ensureWalletHasAddressIndex(
        MoneroAccount $account,
        int $targetIndex,
        int $batchSize = 1000,
        ?callable $onBatch = null
    ): int {
        $wallet = $account->wallet;

        $result = Monero::generalAtomicLock($wallet, function () use ($account, $wallet, $targetIndex) {
            $api = $wallet->node->api();
            $api->openWallet($wallet->name, $wallet->password);
            return $api->getAddressByIndex($account->account_index, [$targetIndex]);
        }, 300);

        if ($result !== null && !empty($result['addresses'][0])) {
            return $targetIndex;
        }

        $currentMaxIndex = Monero::generalAtomicLock($wallet, function () use ($account, $wallet) {
            $api = $wallet->node->api();
            $api->openWallet($wallet->name, $wallet->password);
            $created = $api->createAddress($account->account_index);
            return $created['address_index'];
        }, 300);

        if ($onBatch) {
            $onBatch($currentMaxIndex, $targetIndex);
        }

        while ($currentMaxIndex < $targetIndex) {
            try {
                $currentMaxIndex = Monero::generalAtomicLock(
                    $wallet,
                    function () use ($account, $wallet, $targetIndex, $batchSize, $currentMaxIndex) {
                        $api = $wallet->node->api();
                        $api->openWallet($wallet->name, $wallet->password);

                        $localMax = $currentMaxIndex;
                        $created = 0;

                        while ($localMax < $targetIndex && $created < $batchSize) {
                            $result = $api->createAddress($account->account_index);
                            $localMax = $result['address_index'];
                            $created++;
                        }

                        return $localMax;
                    },
                    300
                );
            } catch (LockTimeoutException) {
                sleep(5);
                continue;
            }

            if ($onBatch) {
                $onBatch($currentMaxIndex, $targetIndex);
            }
        }

        return $currentMaxIndex;
    }

    public function createAddressesInWalletOnly(
        MoneroAccount $account,
        int $count,
        int $batchSize = 1000,
        ?callable $onBatch = null
    ): int {
        $wallet = $account->wallet;
        $totalCreated = 0;

        while ($totalCreated < $count) {
            $batchCount = min($batchSize, $count - $totalCreated);

            try {
                $created = Monero::generalAtomicLock(
                    $wallet,
                    function () use ($account, $wallet, $batchCount) {
                        $api = $wallet->node->api();
                        $api->openWallet($wallet->name, $wallet->password);

                        for ($i = 0; $i < $batchCount; $i++) {
                            $api->createAddress($account->account_index);
                        }

                        return $batchCount;
                    },
                    300
                );
            } catch (LockTimeoutException) {
                sleep(5);
                continue;
            }

            $totalCreated += $created;

            if ($onBatch) {
                $onBatch($totalCreated, $count);
            }
        }

        return $totalCreated;
    }

    public function getWalletAddressCount(MoneroAccount $account): int
    {
        return Monero::generalAtomicLock($account->wallet, function () use ($account) {
            $wallet = $account->wallet;
            $api = $wallet->node->api();
            $api->openWallet($wallet->name, $wallet->password);

            $addressInfo = $api->getAddress($account->account_index);
            return count($addressInfo['addresses']);
        });
    }
}
