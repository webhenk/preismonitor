<?php

declare(strict_types=1);

class PriceParser
{
    public const DEFAULT_TOTAL_REGEX = '/Gesamtpreis[^0-9]*([0-9,.]+)/i';
    private const FALLBACK_TOTAL_REGEXES = [
        '/tcpPrice__value[^0-9]*([0-9][0-9.,\s\x{00A0}]*[0-9])/iu',
        '/tcpPrice__value[^0-9]*([0-9][0-9.,\s\x{00A0}&;]*[0-9])/iu',
    ];

    private array $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function interpolateUrl(string $url, string $date): string
    {
        if ($date === '') {
            return $url;
        }

        return str_replace('{date}', $date, $url);
    }

    public function fetchPage(string $url): string
    {
        $result = $this->fetchPageWithInfo($url);
        $error = $result['error'];
        $status = $result['status'];
        $body = $result['body'];

        if ($error !== null) {
            throw new RuntimeException("Request failed for {$url}: {$error}");
        }

        if ($status >= 400) {
            throw new RuntimeException("HTTP {$status} for {$url}");
        }

        if ($body === null || $body === '') {
            throw new RuntimeException("Empty response body for {$url}");
        }

        return $body;
    }

    public function fetchPageWithInfo(string $url): array
    {
        $userAgent = $this->settings['user_agent'] ?? 'PreisMonitor/1.0 (+https://example.com)';
        $timeout = (int)($this->settings['timeout_seconds'] ?? 20);

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
        $info = curl_getinfo($ch);
        $status = (int)($info['http_code'] ?? 0);
        $contentType = (string)($info['content_type'] ?? '');
        $totalTime = (float)($info['total_time'] ?? 0.0);
        $sizeDownload = (float)($info['size_download'] ?? 0.0);
        $effectiveUrl = (string)($info['url'] ?? $url);
        curl_close($ch);

        return [
            'body' => $response === false ? null : $response,
            'error' => $response === false ? $error : null,
            'status' => $status,
            'content_type' => $contentType,
            'total_time' => $totalTime,
            'size_download' => $sizeDownload,
            'effective_url' => $effectiveUrl,
        ];
    }

    public function extractTotalPrice(string $html, ?string $regex = null): ?array
    {
        $priceInfo = $this->extractPrice($html, [
            'room_hint' => 'Gesamtpreis',
            'price_regex' => $regex ?? self::DEFAULT_TOTAL_REGEX,
        ]);

        if ($priceInfo !== null || $regex !== null) {
            return $priceInfo;
        }

        foreach (self::FALLBACK_TOTAL_REGEXES as $fallbackRegex) {
            $priceInfo = $this->extractPrice($html, [
                'room_hint' => 'tcpPrice__value',
                'price_regex' => $fallbackRegex,
            ]);
            if ($priceInfo !== null) {
                return $priceInfo;
            }
        }

        return null;
    }

    public function extractPrice(string $html, array $room): ?array
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

    public function parseApiResponse(string $json): array
    {
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid API response payload.');
        }

        $rooms = $payload['rooms'] ?? null;
        if ($rooms === null && isset($payload['room'])) {
            $rooms = [$payload['room']];
        }

        if (!is_array($rooms)) {
            throw new RuntimeException('API response does not contain rooms.');
        }

        $parsed = [];
        foreach ($rooms as $room) {
            if (!is_array($room)) {
                continue;
            }
            $parsed[] = $this->parseApiRoom($room);
        }

        if ($parsed === []) {
            throw new RuntimeException('API response does not contain valid room entries.');
        }

        return $parsed;
    }

    private function parseApiRoom(array $room): array
    {
        $pricing = $room['pricing'] ?? [];
        $total = $pricing['total']['amount'] ?? $pricing['total'] ?? null;
        $night = $pricing['night']['amount'] ?? $pricing['night'] ?? null;
        $currency = $pricing['total']['currency'] ?? $pricing['night']['currency'] ?? $room['currency'] ?? null;

        $blocked = false;
        if (isset($room['blocked'])) {
            $blocked = (bool)$room['blocked'];
        }

        $status = strtolower((string)($room['status'] ?? ''));
        if (in_array($status, ['blocked', 'sold_out', 'unavailable', 'closed'], true)) {
            $blocked = true;
        }

        if (array_key_exists('available', $room) && $room['available'] === false) {
            $blocked = true;
        }

        return [
            'name' => (string)($room['name'] ?? ''),
            'total' => $this->normalizeApiValue($total),
            'night' => $this->normalizeApiValue($night),
            'currency' => $this->normalizeCurrency($currency),
            'blocked' => $blocked,
        ];
    }

    private function normalizeApiValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = preg_replace('/[^0-9,\.]/', '', $value);
        if ($normalized === null || $normalized === '') {
            return null;
        }

        $normalized = str_replace(' ', '', $normalized);
        $hasComma = str_contains($normalized, ',');
        $hasDot = str_contains($normalized, '.');

        if ($hasComma && $hasDot) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif ($hasComma) {
            $normalized = str_replace(',', '.', $normalized);
        } elseif (substr_count($normalized, '.') > 1) {
            $parts = explode('.', $normalized);
            $decimal = array_pop($parts);
            $normalized = implode('', $parts) . '.' . $decimal;
        }

        return (float)$normalized;
    }

    private function normalizeCurrency(mixed $currency): ?string
    {
        if ($currency === null) {
            return null;
        }

        $currency = strtoupper(trim((string)$currency));

        return $currency === '' ? null : $currency;
    }
}
