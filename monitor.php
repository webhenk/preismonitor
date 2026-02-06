<?php

declare(strict_types=1);

const CONFIG_DIR = __DIR__ . '/config';
const DATA_DIR = __DIR__ . '/data';

require __DIR__ . '/src/PriceParser.php';

function readJson(string $path): array
{
    if (!file_exists($path)) {
        throw new RuntimeException("Missing config file: {$path}");
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Unable to read config file: {$path}");
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Invalid JSON in {$path}");
    }

    return $decoded;
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
        throw new RuntimeException("Unable to create directory: {$path}");
    }
}

function writeDailyResult(array $entries): string
{
    ensureDirectory(DATA_DIR);
    $date = (new DateTimeImmutable('now'))->format('Y-m-d');
    $path = DATA_DIR . "/{$date}.txt";
    $lines = [];

    foreach ($entries as $entry) {
        $lines[] = implode(' | ', [
            $entry['timestamp'],
            $entry['target_id'],
            $entry['room_name'],
            $entry['price_raw'] ?? 'not_found',
            $entry['price_value'] ?? 'n/a',
            $entry['url'],
        ]);
    }

    $payload = implode(PHP_EOL, $lines) . PHP_EOL;
    if (file_put_contents($path, $payload, FILE_APPEND) === false) {
        throw new RuntimeException("Unable to write to {$path}");
    }

    return $path;
}

function sendAlert(array $emailSettings, array $entry): void
{
    if (!($emailSettings['enabled'] ?? false)) {
        return;
    }

    $to = $emailSettings['to'] ?? null;
    if (!$to) {
        throw new RuntimeException('Email alert enabled but no recipient configured.');
    }

    $from = $emailSettings['from'] ?? 'preis-monitor@localhost';
    $subjectPrefix = $emailSettings['subject_prefix'] ?? '[PreisMonitor]';
    $subject = sprintf('%s Price alert for %s', $subjectPrefix, $entry['room_name']);

    $message = sprintf(
        "Price alert triggered!\n\nTarget: %s\nRoom: %s\nPrice: %s (%s)\nThreshold: %s\nURL: %s\nTime: %s\n",
        $entry['target_id'],
        $entry['room_name'],
        $entry['price_raw'] ?? 'n/a',
        $entry['price_value'] ?? 'n/a',
        $entry['threshold'] ?? 'n/a',
        $entry['url'],
        $entry['timestamp'],
    );

    $headers = [
        'From: ' . $from,
    ];

    mail($to, $subject, $message, implode("\r\n", $headers));
}

function main(): void
{
    $settings = readJson(CONFIG_DIR . '/settings.json');
    $targets = readJson(CONFIG_DIR . '/targets.json');
    $parser = new PriceParser($settings);

    $entries = [];

    foreach ($targets as $target) {
        $targetId = $target['id'] ?? 'unknown';
        $date = $target['date'] ?? (new DateTimeImmutable('now'))->format('Y-m-d');
        $url = $parser->interpolateUrl($target['url'], $date);

        echo "Fetching {$targetId}..." . PHP_EOL;
        $fetchResult = $parser->fetchPage($url);
        $state = (string)($fetchResult['state'] ?? 'error');
        $status = (int)($fetchResult['status'] ?? 0);

        if ($state !== 'ok') {
            $message = match ($state) {
                'blocked' => 'blocked',
                'http_error' => 'HTTP ' . $status,
                'empty' => 'Empty response body',
                default => 'Request failed',
            };
            if ($state === 'error' && !empty($fetchResult['error'])) {
                $message .= ': ' . (string)$fetchResult['error'];
            }
            echo "Skipping {$targetId}: {$message}" . PHP_EOL;
            continue;
        }

        $html = (string)($fetchResult['body'] ?? '');

        foreach ($target['rooms'] ?? [] as $room) {
            $roomName = $room['name'] ?? 'Unnamed room';
            $priceInfo = $parser->extractPrice($html, $room);

            $entry = [
                'timestamp' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
                'target_id' => $targetId,
                'room_name' => $roomName,
                'price_raw' => $priceInfo['raw'] ?? null,
                'price_value' => $priceInfo['value'] ?? null,
                'url' => $url,
                'threshold' => $room['threshold'] ?? null,
            ];

            $entries[] = $entry;

            if ($priceInfo !== null && isset($room['threshold'])) {
                if ($priceInfo['value'] <= (float)$room['threshold']) {
                    sendAlert($settings['email'] ?? [], $entry);
                }
            }
        }
    }

    $path = writeDailyResult($entries);
    echo "Results saved to {$path}" . PHP_EOL;
}

main();
