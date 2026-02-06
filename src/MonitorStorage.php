<?php

declare(strict_types=1);

class MonitorStorage
{
    private string $monitorFile;
    private string $historyFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            if (!mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
                throw new RuntimeException("Unable to create data directory: {$dataDir}");
            }
        }

        $this->monitorFile = rtrim($dataDir, '/').'/monitors.json';
        $this->historyFile = rtrim($dataDir, '/').'/history.json';
    }

    public function getMonitors(): array
    {
        return $this->readJson($this->monitorFile, []);
    }

    public function saveMonitors(array $monitors): void
    {
        $this->writeJson($this->monitorFile, $monitors);
    }

    public function getHistory(): array
    {
        return $this->readJson($this->historyFile, []);
    }

    public function addHistory(array $entry): void
    {
        $history = $this->getHistory();
        $history[] = $entry;
        $this->writeJson($this->historyFile, $history);
    }

    private function readJson(string $path, array $default): array
    {
        if (!file_exists($path)) {
            return $default;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read data file: {$path}");
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid JSON in {$path}");
        }

        return $decoded;
    }

    private function writeJson(string $path, array $payload): void
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode JSON payload.');
        }

        if (file_put_contents($path, $encoded.PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write data file: {$path}");
        }
    }
}
