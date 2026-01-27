<?php

declare(strict_types=1);

const CONFIG_DIR = __DIR__ . '/config';
const DATA_DIR = __DIR__ . '/data';

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

function interpolateUrl(string $url, string $date): string
{
    return str_replace('{date}', $date, $url);
}

function fetchPage(string $url, array $settings): string
{
    $userAgent = $settings['user_agent'] ?? 'PreisMonitor/1.0 (+https://example.com)';
    $timeout = (int)($settings['timeout_seconds'] ?? 20);

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException("Unable to initialize curl for {$url}");
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException("Request failed for {$url}: {$error}");
    }

    if ($status >= 400) {
        throw new RuntimeException("HTTP {$status} for {$url}");
    }

    return $response;
}

function extractPrice(string $html, array $room): ?array
{
    $regex = $room['price_regex'] ?? null;
    if (!$regex) {
        throw new RuntimeException('Missing price_regex for room entry.');
    }

    $subject = $html;
    $roomHint = $room['room_hint'] ?? null;
    if ($roomHint) {
        $pos = stripos($html, $roomHint);
        if ($pos !== false) {
            $subject = substr($html, max(0, $pos - 500), 2000);
        }
    }

    if (!preg_match($regex, $subject, $matches)) {
        return null;
    }

    $raw = $matches[1] ?? $matches[0];
    $normalized = preg_replace('/[^0-9,\.]/', '', $raw);
    if ($normalized === null || $normalized === '') {
        return null;
    }

    $normalized = str_replace(['.', ' '], ['', ''], $normalized);
    $normalized = str_replace(',', '.', $normalized);

    return [
        'raw' => $raw,
        'value' => (float)$normalized,
    ];
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

    $entries = [];

    foreach ($targets as $target) {
        $targetId = $target['id'] ?? 'unknown';
        $date = $target['date'] ?? (new DateTimeImmutable('now'))->format('Y-m-d');
        $url = interpolateUrl($target['url'], $date);

        echo "Fetching {$targetId}..." . PHP_EOL;
        $html = fetchPage($url, $settings);

        foreach ($target['rooms'] ?? [] as $room) {
            $roomName = $room['name'] ?? 'Unnamed room';
            $priceInfo = extractPrice($html, $room);

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
