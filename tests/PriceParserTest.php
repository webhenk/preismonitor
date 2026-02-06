<?php

declare(strict_types=1);

require __DIR__ . '/../src/PriceParser.php';

$parser = new PriceParser([]);
$fixturesDir = __DIR__ . '/../artifacts/debug';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . sprintf(' Expected %s, got %s.', var_export($expected, true), var_export($actual, true)));
    }
}

function assertFloatEquals(float $expected, float $actual, string $message, float $delta = 0.001): void
{
    if (abs($expected - $actual) > $delta) {
        throw new RuntimeException($message . sprintf(' Expected %.3f, got %.3f.', $expected, $actual));
    }
}

$tests = [];

$tests['host_total_price_parses_from_html_dump'] = static function () use ($parser, $fixturesDir): void {
    $html = file_get_contents($fixturesDir . '/host_total.html');
    if ($html === false) {
        throw new RuntimeException('Fixture host_total.html missing.');
    }

    $priceInfo = $parser->extractTotalPrice($html);
    if ($priceInfo === null) {
        throw new RuntimeException('Expected total price info to be parsed.');
    }

    assertSameValue('1.234,56', $priceInfo['raw'], 'Raw total price mismatch.');
    assertFloatEquals(1234.56, $priceInfo['value'], 'Normalized total price mismatch.');
};

$tests['host_room_price_parses_from_html_dump'] = static function () use ($parser, $fixturesDir): void {
    $html = file_get_contents($fixturesDir . '/host_room.html');
    if ($html === false) {
        throw new RuntimeException('Fixture host_room.html missing.');
    }

    $room = [
        'name' => 'Deluxe Queen',
        'room_hint' => 'Deluxe Queen',
        'price_regex' => '/€\s*([0-9,.]+)/',
    ];

    $priceInfo = $parser->extractPrice($html, $room);
    if ($priceInfo === null) {
        throw new RuntimeException('Expected room price info to be parsed.');
    }

    assertSameValue('189,00', $priceInfo['raw'], 'Raw room price mismatch.');
    assertFloatEquals(189.00, $priceInfo['value'], 'Normalized room price mismatch.');
};

$tests['api_parser_normalizes_currency_and_night_total'] = static function () use ($parser, $fixturesDir): void {
    $json = file_get_contents($fixturesDir . '/api_response_available.json');
    if ($json === false) {
        throw new RuntimeException('Fixture api_response_available.json missing.');
    }

    $rooms = $parser->parseApiResponse($json);
    $room = $rooms[0] ?? null;
    if ($room === null) {
        throw new RuntimeException('Expected at least one room in API response.');
    }

    assertSameValue('EUR', $room['currency'], 'Currency normalization failed.');
    assertFloatEquals(1234.56, $room['total'], 'Total normalization failed.');
    assertFloatEquals(123.45, $room['night'], 'Night normalization failed.');
    assertSameValue(false, $room['blocked'], 'Blocked detection should be false.');
};

$tests['api_parser_detects_blocked_rooms'] = static function () use ($parser, $fixturesDir): void {
    $json = file_get_contents($fixturesDir . '/api_response_blocked.json');
    if ($json === false) {
        throw new RuntimeException('Fixture api_response_blocked.json missing.');
    }

    $rooms = $parser->parseApiResponse($json);
    $room = $rooms[0] ?? null;
    if ($room === null) {
        throw new RuntimeException('Expected at least one room in API response.');
    }

    assertSameValue('USD', $room['currency'], 'Currency should be preserved for blocked room.');
    assertFloatEquals(499.99, $room['total'], 'Total normalization failed for blocked room.');
    assertSameValue(null, $room['night'], 'Night should be null when not present.');
    assertSameValue(true, $room['blocked'], 'Blocked detection failed.');
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "[PASS] {$name}" . PHP_EOL;
    } catch (Throwable $exception) {
        $failures++;
        echo "[FAIL] {$name}: {$exception->getMessage()}" . PHP_EOL;
    }
}

if ($failures > 0) {
    exit(1);
}

