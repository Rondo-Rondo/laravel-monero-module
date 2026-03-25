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
    protected ?string $proxy;
    protected ?int $pid;

    public function __construct(
        string  $host,
        int     $port,
        ?string $username = null,
        ?string $password = null,
        ?string $daemon = null,
        ?string $proxy = null,
    )
    {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->daemon = $daemon;
        $this->proxy = $proxy;
    }

    private function getScheme(): string
    {
        $localHosts = ['127.0.0.1', 'localhost', '0.0.0.0'];

        if (in_array($this->host, $localHosts)) {
            return 'http';
        }

        return 'https';
    }

    private function buildErrorMessage(string $method, string $url, $response, array $params = [], string $extra = ''): string
    {
        $parts = [
            "RPC method: {$method}",
            "URL: {$url}",
            "HTTP status: {$response->status()}",
        ];

        $body = $response->body();
        $parts[] = $body !== '' ? "Body: {$body}" : 'Body: (empty)';

        $headers = $response->headers();
        if ($headers) {
            $flat = [];
            foreach ($headers as $key => $values) {
                $flat[] = "{$key}: " . implode(', ', (array)$values);
            }
            $parts[] = "Headers: {" . implode('; ', $flat) . "}";
        }

        if ($params) {
            $parts[] = "Params: " . json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($extra !== '') {
            $parts[] = $extra;
        }

        return implode(' | ', $parts);
    }

    public function request(string $method, array $params = [], bool $daemon = false, array $ignoreErrorCodes = []): mixed
    {
        $requestId = Str::uuid()->toString();

        if ($daemon && $this->daemon) {
            $parsed = parse_url($this->daemon);
            $daemonUrl = ($parsed['scheme'] ?? 'http') . '://' . $parsed['host']
                . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
            $daemonUser = $parsed['user'] ?? null;
            $daemonPass = $parsed['pass'] ?? null;

            $requestUrl = $daemonUrl . '/' . $method;

            $http = Http::timeout(120)
                ->connectTimeout(10);

            if ($daemonUser && $daemonPass) {
                $http = $http->withDigestAuth($daemonUser, $daemonPass);
            }

            if ($this->proxy && str_contains($parsed['host'] ?? '', '.onion')) {
                $http = $http->withOptions(['proxy' => $this->proxy]);
            }

            if (count($params)) {
                $response = $http->post($requestUrl, $params);
            } else {
                $response = $http->get($requestUrl);
            }

            $result = $response->json();
            if (empty($result)) {
                throw new \Exception($this->buildErrorMessage($method, $requestUrl, $response, $params));
            }
        } else {
            $requestUrl = $this->getScheme() . '://' . $this->host . ':' . $this->port . '/json_rpc';

            $response = Http::withDigestAuth($this->username ?? '', $this->password ?? '')
//            $response = Http::withoutVerifying()
                ->timeout(120)
                ->connectTimeout(10)
                ->post($requestUrl, [
                    'jsonrpc' => '2.0',
                    'id' => $requestId,
                    'method' => $method,
                    'params' => $params
                ]);

            $result = $response->json();
            if (empty($result)) {
                throw new \Exception($this->buildErrorMessage($method, $requestUrl, $response, $params));
            }

            if ($result['id'] !== $requestId) {
                throw new \Exception('Request ID is not correct');
            }
        }

        if (isset($result['error'])) {
            if ($ignoreErrorCodes && in_array($result['error']['code'], $ignoreErrorCodes)) {
                return null;
            }
            throw new \Exception($this->buildErrorMessage(
                $method,
                $requestUrl,
                $response,
                $params,
                "RPC error code: {$result['error']['code']}, message: {$result['error']['message']}"
            ));
        }

        if (count($result ?? []) === 0) {
            throw new \Exception($this->buildErrorMessage($method, $requestUrl, $response, $params));
        }

        return $result['result'] ?? $result;
    }

    public function getDaemonHeight(): int
    {
        $data = $this->request('get_height', [], true);
        if (!isset($data['height'])) {
            throw new \Exception(print_r($data, true));
        }

        return $data['height'];
    }

    public function getHeight(): int
    {
        $data = $this->request('get_height');
        if (!isset($data['height'])) {
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
        string  $name,
        string  $address,
        string  $viewKey,
        string  $spendKey,
        ?string $password = null,
        ?int    $restoreHeight = null,
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
        if (($data['address'] ?? null) !== $address) {
            throw new \Exception(print_r($data, true));
        }

        return $data['info'] ?? null;
    }

    public function restoreDeterministicWallet(
        string  $name,
        ?string $password,
        string  $mnemonic,
        ?int    $restoreHeight = null,
        ?string $language = null
    ): void
    {
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

    public function getAddressByIndex(int $accountIndex, array $addressIndices): ?array
    {
        return $this->request('get_address', [
            'account_index' => $accountIndex,
            'address_index' => $addressIndices,
        ], false, [-15]);
    }

    public function getTransferByTxid(string $txid, ?int $accountIndex = null): ?array
    {
        $params = ['txid' => $txid];

        if ($accountIndex !== null) {
            $params['account_index'] = $accountIndex;
        }

        return $this->request('get_transfer_by_txid', $params, false, [-8]);
    }

    public function scanTx(array $txids): void
    {
        $this->request('scan_tx', ['txids' => $txids]);
    }

    public function getViewKey(): ?string
    {
        return $this->queryKey('view_key');
    }

    public function getSpendKey(): ?string
    {
        return $this->queryKey('spend_key');
    }

    public function getTxKey(string $txid): ?string
    {
        $data = $this->request('get_tx_key', ['txid' => $txid]);

        return $data['tx_key'] ?? null;
    }

    public function checkTxKey(string $txid, string $txKey, string $address): array
    {
        return $this->request('check_tx_key', [
            'txid' => $txid,
            'tx_key' => $txKey,
            'address' => $address,
        ]);
    }

    public function getTxProof(string $txid, string $address, ?string $message = null): ?string
    {
        $params = [
            'txid' => $txid,
            'address' => $address,
        ];

        if ($message !== null) {
            $params['message'] = $message;
        }

        $data = $this->request('get_tx_proof', $params);

        return $data['signature'] ?? null;
    }

    public function checkTxProof(string $txid, string $address, string $signature, ?string $message = null): array
    {
        $params = [
            'txid' => $txid,
            'address' => $address,
            'signature' => $signature,
        ];

        if ($message !== null) {
            $params['message'] = $message;
        }

        return $this->request('check_tx_proof', $params);
    }
}
