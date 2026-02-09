<?php

namespace Mollsoft\LaravelMoneroModule\Concerns;

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
                // Проверить существует ли в БД
                $existing = $account->addresses()->where('address_index', $index)->first();
                if ($existing) {
                    return $existing;
                }

                // Попробовать получить из wallet
                $result = $api->getAddressByIndex($account->account_index, [$index]);
                if (!empty($result['addresses'][0])) {
                    return $account->addresses()->create([
                        'wallet_id' => $wallet->id,
                        'address' => $result['addresses'][0]['address'],
                        'address_index' => $result['addresses'][0]['address_index'],
                        'title' => $title,
                    ]);
                }

                // Если нет - создать последовательно
                $last = $account->addresses()->orderBy('address_index', 'desc')->first();
                $current = $last ? $last->address_index : -1;

                while ($current < $index) {
                    $created = $api->createAddress($account->account_index);
                    $account->addresses()->create([
                        'wallet_id' => $wallet->id,
                        'address' => $created['address'],
                        'address_index' => $created['address_index'],
                        'title' => null,
                    ]);
                    $current = $created['address_index'];
                }

                return $account->addresses()->where('address_index', $index)->first();
            }

            // Без индекса создаем по текущему порядку в кошельке
            $createAddress = $api->createAddress($account->account_index);
            return $account->addresses()->create([
                'wallet_id' => $wallet->id,
                'address' => $createAddress['address'],
                'address_index' => $createAddress['address_index'],
                'title' => $title,
            ]);
        });
    }

    public function validateAddress(MoneroNode $node, string $address): bool
    {
        $api = $node->api();

        $details = $api->validateAddress($address);

        return (bool)$details['valid'];
    }
}
