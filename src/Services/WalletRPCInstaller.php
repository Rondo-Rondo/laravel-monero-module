<?php

namespace Mollsoft\LaravelMoneroModule\Services;

use Illuminate\Support\Facades\File;

class WalletRPCInstaller extends BaseConsole
{
    protected string $version = 'v0.18.4.6';
    protected string $storagePath;

    public function __construct()
    {
        $this->storagePath = storage_path('app/monero');
    }

    public function run(): void
    {
        parent::run();
        $this->install();
    }

    protected function install(): bool
    {
        $os = PHP_OS_FAMILY;
        $arch = php_uname('m');
        $this->log("Операционная система: $os, архитектура: $arch");

        $url = $this->getDownloadUrl($os, $arch);
        if (!$url) {
            $this->log('❌ Не удалось определить ссылку для скачивания Monero.', 'error');
            return false;
        }

        // Временная рабочая директория в /tmp
        $tempRoot = '/tmp/monero-temp-' . time();
        $archive = "$tempRoot/monero.tar.bz2";
        $outputDir = "$tempRoot/extracted";

        File::makeDirectory($outputDir, 0755, true, true);

        $this->log("📥 Скачивание Monero с: $url");
        $this->downloadWithProgress($url, $archive);
        $this->log('✅ Скачивание завершено, распаковка...', 'success');

        shell_exec("tar -xvjf $archive -C $outputDir");
        $this->log('✅ Распаковка завершена!', 'success');

        $rpcPath = $this->findBinary($outputDir, 'monero-wallet-rpc');
        if (!$rpcPath) {
            $this->log('❌ Не удалось найти бинарник monero-wallet-rpc', 'error');
            return false;
        }

        $finalPath = base_path('monero-wallet-rpc');
        if (File::exists($finalPath)) {
            File::delete($finalPath);
        }

        File::move($rpcPath, $finalPath);
        chmod($finalPath, 0755);
        $this->log("✅ monero-wallet-rpc установлен: $finalPath", 'success');

        // 🧹 Удаляем /tmp
        $this->log('🧹 Очистка временных файлов...');
        if (File::isDirectory($tempRoot)) {
            File::deleteDirectory($tempRoot);
            $this->log("🗑 Удалена временная папка $tempRoot");
        }

        $this->log('✅ Установка завершена!', 'success');
        return true;
    }

    protected function downloadWithProgress(string $url, string $destination): void
    {
        $fp = fopen($destination, 'w+');
        if (!$fp) {
            throw new \RuntimeException("Не удалось открыть файл для записи: $destination");
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function (
            $resource,
            float $downloadSize,
            float $downloaded,
            float $uploadSize,
            float $uploaded
        ) {
            if ($downloadSize > 0) {
                $percent = round($downloaded * 100 / $downloadSize, 1);
                echo "\r📦 Прогресс загрузки: {$percent}%";
            }
        });

        $result = curl_exec($ch);
        if ($result === false) {
            throw new \RuntimeException("Ошибка при скачивании: " . curl_error($ch));
        }

        curl_close($ch);
        fclose($fp);
        echo "\n";
    }

    protected function getDownloadUrl(string $os, string $arch): ?string
    {
        $base = 'https://downloads.getmonero.org/cli';
        $version = $this->version;

        $arch = strtolower($arch);

        return match (true) {
            $os === 'Linux' && str_contains($arch, 'x86_64') => "$base/monero-linux-x64-{$version}.tar.bz2",
            $os === 'Linux' && (str_contains($arch, 'aarch64') || str_contains($arch, 'arm64')) => "$base/monero-linux-armv8-{$version}.tar.bz2",
            $os === 'Darwin' && str_contains($arch, 'x86_64') => "$base/monero-mac-x64-{$version}.tar.bz2",
            $os === 'Darwin' && (str_contains($arch, 'arm') || str_contains($arch, 'aarch64')) => "$base/monero-mac-armv8-{$version}.tar.bz2",
            default => null,
        };
    }

    protected function findBinary(string $dir, string $binaryName): ?string
    {
        $files = scandir($dir);
        foreach ($files as $file) {
            $fullPath = $dir . '/' . $file;
            if (is_dir($fullPath) && !in_array($file, ['.', '..'])) {
                $found = $this->findBinary($fullPath, $binaryName);
                if ($found) return $found;
            } elseif (is_file($fullPath) && basename($fullPath) === $binaryName) {
                return $fullPath;
            }
        }
        return null;
    }

    public function getBinaryPath(): string
    {
        return $this->storagePath . '/monero-wallet-rpc';
    }
}