<?php

namespace Mollsoft\LaravelMoneroModule\Api;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mollsoft\LaravelMoneroModule\Models\MoneroNode;

class Api
{
    protected string $host;
    protected int $port;
    protected ?string $username;
    protected ?string $password;
    protected ?string $daemon;
    protected ?int $pid;

    public function __construct(
        string $host,
        int $port,
        ?string $username = null,
        ?string $password = null,
        ?string $daemon = null,
    )
    {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->daemon = $daemon;
    }

    public function request(string $method, array $params = [], bool $daemon = false): mixed
    {
        $requestId = Str::uuid()->toString();

        if( $daemon && $this->daemon ) {
            $response = Http::timeout(60)
                ->connectTimeout(10);

            if( count($params) ) {
                $response = $response->post('https://'.$this->daemon.'/'.$method, $params);
            }
            else {
                $response = $response->get('https://'.$this->daemon.'/'.$method);
            }

            $result = $response->json();
            if (empty($result)) {
                throw new \Exception($response->body());
            }
        }
        else {
            $response = Http::withDigestAuth($this->username ?? '', $this->password ?? '')
//            $response = Http::withoutVerifying()
                ->timeout(60)
                ->connectTimeout(10)
                ->post('https://'.$this->host.':'.$this->port.'/json_rpc', [
                    'jsonrpc' => '2.0',
                    'id' => $requestId,
                    'method' => $method,
                    'params' => $params
                ]);

            $result = $response->json();
            if (empty($result)) {
                throw new \Exception($response->body());
            }

            if ($result['id'] !== $requestId) {
                throw new \Exception('Request ID is not correct');
            }
        }

        if (isset($result['error'])) {
            throw new \Exception($result['error']['message']);
        }

        if (count($result ?? []) === 0) {
            throw new \Exception($response->body());
        }

        return $result['result'] ?? $result;
    }

    public function getDaemonHeight(): int
    {
        $data = $this->request('get_height', [], true);
        if( !isset( $data['height'] ) ) {
            throw new \Exception(print_r($data, true));
        }

        return $data['height'];
    }

    public function getHeight(): int
    {
        $data = $this->request('get_height');
        if( !isset( $data['height'] ) ) {
            throw new \Exception(print_r($data, true));
        }

        return $data['height'];
    }

    public function openWallet(string $name, ?string $password = null): void
    {
        $this->request('open_wallet', [
            'filename' => $name,
            'password' => $password,
        ]);
    }

    public function refresh(): void
    {
        $this->request('refresh');
    }

    public function getAllBalance(): array
    {
        return $this->request('get_balance', [
            'all_accounts' => true,
        ]);
    }

    public function getAccountBalance(int $index): array
    {
        return $this->request('get_balance', [
            'account_index' => $index,
        ]);
    }

    public function createAccount(): array
    {
        return $this->request('create_account');
    }

    public function createAddress(int $accountIndex): array
    {
        return $this->request('create_address', [
            'account_index' => $accountIndex,
        ]);
    }

    public function validateAddress(string $address): array
    {
        return $this->request('validate_address', [
            'address' => $address,
        ]);
    }

    public function getVersion(): array
    {
        return $this->request('get_version');
    }

    public function createWallet(string $name, ?string $password = null, ?string $language = null): void
    {
        $language = $language ?? 'English';

        $this->request('create_wallet', [
            'filename' => $name,
            'password' => $password,
            'language' => $language
        ]);
    }

    public function queryKey(string $keyType): mixed
    {
        return $this->request('query_key', ['key_type' => $keyType])['key'] ?? null;
    }

    public function getAccounts(): array
    {
        return $this->request('get_accounts');
    }

    public function generateFromKeys(
        string $name,
        string $address,
        string $viewKey,
        string $spendKey,
        ?string $password = null,
        ?int $restoreHeight = null,
    ): ?string
    {
        $data = $this->request('generate_from_keys', [
            'restore_height' => $restoreHeight ?? 0,
            'filename' => $name,
            'address' => $address,
            'spendkey' => $spendKey,
            'viewkey' => $viewKey,
            'password' => $password,
        ]);
        if( ($data['address'] ?? null) !== $address ) {
            throw new \Exception(print_r($data, true));
        }

        return $data['info'] ?? null;
    }

    public function restoreDeterministicWallet(
        string $name,
        ?string $password,
        string $mnemonic,
        ?int $restoreHeight = null,
        ?string $language = null
    ): void {
        $language = $language ?? 'English';

        $this->request('restore_deterministic_wallet', [
            'filename' => $name,
            'password' => $password,
            'seed' => $mnemonic,
            'restore_height' => $restoreHeight,
            'language' => $language,
        ]);
    }

    public function changeWalletPassword(?string $oldPassword, ?string $newPassword): void
    {
        $this->request('change_wallet_password', [
            'old_password' => $oldPassword,
            'new_password' => $newPassword,
        ]);
    }

    public function getAddress(?int $accountIndex = null): array
    {
        return $this->request('get_address', [
            'account_index' => $accountIndex,
        ]);
    }

    public function getAddressByIndex(int $accountIndex, array $addressIndices): array
    {
        return $this->request('get_address', [
            'account_index' => $accountIndex,
            'address_index' => $addressIndices,
        ]);
    }
}
