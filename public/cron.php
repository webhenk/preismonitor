<?php

declare(strict_types=1);

const CONFIG_DIR = __DIR__ . '/../config';
const DATA_DIR = __DIR__ . '/../data';

require __DIR__ . '/../src/PriceParser.php';
require __DIR__ . '/../src/MonitorStorage.php';

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

function runMonitorChecks(array $monitors, PriceParser $parser, MonitorStorage $storage): array
{
    $now = new DateTimeImmutable('now');

    foreach ($monitors as &$monitor) {
        if (!($monitor['active'] ?? false)) {
            continue;
        }

        $date = (string)($monitor['date'] ?? '');
        $resolvedUrl = $parser->interpolateUrl((string)$monitor['url'], $date);

        $fetchResult = $parser->fetchPage($resolvedUrl);
        $state = (string)($fetchResult['state'] ?? 'error');
        $status = (int)($fetchResult['status'] ?? 0);
        $errorMessage = null;

        if ($state === 'blocked') {
            $errorMessage = 'blocked';
        } elseif ($state === 'http_error') {
            $errorMessage = 'HTTP ' . $status;
        } elseif ($state === 'error') {
            $errorMessage = 'Request failed';
            if (!empty($fetchResult['error'])) {
                $errorMessage .= ': ' . (string)$fetchResult['error'];
            }
        } elseif ($state === 'empty') {
            $errorMessage = 'Empty response body';
        }

        if ($errorMessage !== null) {
            $storage->addHistory([
                'id' => $monitor['id'],
                'url' => $monitor['url'],
                'resolved_url' => $resolvedUrl,
                'checked_at' => $now->format(DateTimeInterface::ATOM),
                'error' => $errorMessage,
            ]);

            $monitor['last_checked_at'] = $now->format(DateTimeInterface::ATOM);
            $monitor['last_error'] = $errorMessage;
            continue;
        }

        $html = (string)($fetchResult['body'] ?? '');
        $priceInfo = $parser->extractTotalPrice($html, $monitor['price_regex'] ?? null);

        if ($priceInfo === null) {
            $errorMessage = 'Kein Gesamtpreis gefunden.';

            $storage->addHistory([
                'id' => $monitor['id'],
                'url' => $monitor['url'],
                'resolved_url' => $resolvedUrl,
                'checked_at' => $now->format(DateTimeInterface::ATOM),
                'error' => $errorMessage,
            ]);

            $monitor['last_checked_at'] = $now->format(DateTimeInterface::ATOM);
            $monitor['last_error'] = $errorMessage;
            continue;
        }

        $storage->addHistory([
            'id' => $monitor['id'],
            'url' => $monitor['url'],
            'resolved_url' => $resolvedUrl,
            'checked_at' => $now->format(DateTimeInterface::ATOM),
            'raw' => $priceInfo['raw'] ?? '',
            'value' => $priceInfo['value'] ?? null,
        ]);

        $monitor['last_checked_at'] = $now->format(DateTimeInterface::ATOM);
        $monitor['last_value'] = $priceInfo['value'] ?? null;
    }
    unset($monitor);

    $storage->saveMonitors($monitors);

    return $monitors;
}

header('Content-Type: application/json');

try {
    $settings = readJson(CONFIG_DIR . '/settings.json');
    $parser = new PriceParser($settings);
    $storage = new MonitorStorage(DATA_DIR);
    $monitors = $storage->getMonitors();

    $monitors = runMonitorChecks($monitors, $parser, $storage);

    echo json_encode([
        'status' => 'ok',
        'checked_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        'monitors' => $monitors,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (RuntimeException $exception) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $exception->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
