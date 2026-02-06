<?php

declare(strict_types=1);

class PriceParser
{
    public const DEFAULT_TOTAL_REGEX = '/Gesamtpreis[^0-9]*([0-9,.]+)/i';
    private const FALLBACK_TOTAL_REGEXES = [
        '/tcpPrice__value[^0-9]*([0-9][0-9.,\s\x{00A0}]*[0-9])/iu',
        '/tcpPrice__value[^0-9]*([0-9][0-9.,\s\x{00A0}&;]*[0-9])/iu',
    ];
    private const HOST_STRATEGIES = [
        [
            'hosts' => ['booking.com', 'www.booking.com'],
            'selectors' => [
                'css' => [
                    '.bui-price-display__value',
                    '.prco-valign-middle-helper',
                    '.bui-price-display__value',
                    '[data-testid="price-and-discounted-price"]',
                ],
                'xpath' => [
                    '//*[contains(@class,"bui-price-display__value")]',
                    '//*[contains(text(),"Gesamt")]',
                ],
            ],
            'fallback_regexes' => [
                self::DEFAULT_TOTAL_REGEX,
            ],
        ],
        [
            'hosts' => ['airbnb.com', 'www.airbnb.com'],
            'selectors' => [
                'css' => [
                    '[data-testid="book-it-default-price"]',
                    '[data-testid="book-it-price"]',
                    '[data-testid="price-item"]',
                ],
                'xpath' => [
                    '//*[@data-testid="book-it-default-price"]',
                    '//*[contains(text(),"pro Nacht")]',
                ],
            ],
            'fallback_regexes' => [],
        ],
        [
            'hosts' => ['default'],
            'selectors' => [
                'css' => [
                    '.price',
                    '.total',
                    '.total-price',
                    '.booking-summary',
                    '[data-testid="total-price"]',
                ],
                'xpath' => [
                    '//*[contains(text(),"Gesamt")]',
                    '//*[contains(text(),"Total")]',
                    '//*[contains(text(),"pro Nacht")]',
                ],
            ],
            'fallback_regexes' => [
                self::DEFAULT_TOTAL_REGEX,
            ],
        ],
    ];
    private const CURRENCY_MAP = [
        '€' => 'EUR',
        'EUR' => 'EUR',
        'CHF' => 'CHF',
        '$' => 'USD',
        'USD' => 'USD',
        '£' => 'GBP',
        'GBP' => 'GBP',
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

    public function extractPriceForUrl(string $url, string $html): ?array
    {
        $strategy = $this->resolveStrategyForUrl($url);
        $dom = $this->createDomDocument($html);
        if ($dom !== null) {
            $texts = $this->extractTextsFromStrategy($dom, $strategy);
            foreach ($texts as $text) {
                $parsed = $this->parsePriceText($text);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        foreach ($strategy['fallback_regexes'] as $regex) {
            $priceInfo = $this->extractPrice($html, [
                'room_hint' => 'Gesamtpreis',
                'price_regex' => $regex,
            ]);
            if ($priceInfo !== null) {
                return $this->decoratePriceResult($priceInfo['raw'], $priceInfo['value'], $this->detectCurrency($priceInfo['raw']));
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

    private function resolveStrategyForUrl(string $url): array
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        foreach (self::HOST_STRATEGIES as $strategy) {
            foreach ($strategy['hosts'] as $pattern) {
                if ($pattern === 'default') {
                    continue;
                }
                if ($host === $pattern || str_ends_with($host, '.' . $pattern)) {
                    return $strategy;
                }
            }
        }

        foreach (self::HOST_STRATEGIES as $strategy) {
            if (in_array('default', $strategy['hosts'], true)) {
                return $strategy;
            }
        }

        return self::HOST_STRATEGIES[0];
    }

    private function createDomDocument(string $html): ?DOMDocument
    {
        if ($html === '') {
            return null;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $dom : null;
    }

    private function extractTextsFromStrategy(DOMDocument $dom, array $strategy): array
    {
        $texts = [];
        $xpath = new DOMXPath($dom);
        $selectors = $strategy['selectors'] ?? [];
        foreach ($selectors['css'] ?? [] as $css) {
            $query = $this->cssToXpath($css);
            foreach ($xpath->query($query) as $node) {
                $text = trim($node->textContent);
                if ($text !== '') {
                    $texts[] = $text;
                }
            }
        }

        foreach ($selectors['xpath'] ?? [] as $query) {
            foreach ($xpath->query($query) as $node) {
                $text = trim($node->textContent);
                if ($text !== '') {
                    $texts[] = $text;
                }
            }
        }

        return array_values(array_unique($texts));
    }

    private function cssToXpath(string $selector): string
    {
        $selector = trim($selector);
        if ($selector === '') {
            return '//*';
        }

        $parts = preg_split('/\s+/', $selector);
        $queries = [];
        foreach ($parts as $part) {
            $queries[] = $this->simpleCssToXpath($part);
        }

        return implode('//', $queries);
    }

    private function simpleCssToXpath(string $selector): string
    {
        if ($selector === '*') {
            return '//*';
        }

        if (str_starts_with($selector, '#')) {
            $id = substr($selector, 1);
            return sprintf('//*[@id="%s"]', $id);
        }

        if (str_starts_with($selector, '.')) {
            $class = substr($selector, 1);
            return sprintf('//*[contains(concat(" ", normalize-space(@class), " "), " %s ")]', $class);
        }

        if (preg_match('/^([a-z0-9]+)\.([a-z0-9_-]+)$/i', $selector, $matches)) {
            return sprintf('//%s[contains(concat(" ", normalize-space(@class), " "), " %s ")]', $matches[1], $matches[2]);
        }

        if (preg_match('/^([a-z0-9]+)#([a-z0-9_-]+)$/i', $selector, $matches)) {
            return sprintf('//%s[@id="%s"]', $matches[1], $matches[2]);
        }

        if (preg_match('/^\[([^=\]]+)=\"?([^\"]+)\"?\]$/', $selector, $matches)) {
            return sprintf('//*[@%s="%s"]', $matches[1], $matches[2]);
        }

        if (preg_match('/^([a-z0-9]+)\[([^=\]]+)=\"?([^\"]+)\"?\]$/i', $selector, $matches)) {
            return sprintf('//%s[@%s="%s"]', $matches[1], $matches[2]);
        }

        return sprintf('//%s', $selector);
    }

    private function parsePriceText(string $text): ?array
    {
        $matches = $this->findPricesInText($text);
        if ($matches === []) {
            return null;
        }

        $result = [
            'raw' => null,
            'value' => null,
            'currency' => null,
        ];

        foreach ($matches as $match) {
            $context = $this->classifyPriceContext($text, $match['offset'], $match['length']);
            $entry = [
                'raw' => $match['raw'],
                'value' => $match['value'],
                'currency' => $match['currency'],
                'qualifier' => $context['qualifier'],
            ];

            if ($context['type'] === 'night' && !isset($result['night'])) {
                $result['night'] = $entry;
            } elseif ($context['type'] === 'total' && !isset($result['total'])) {
                $result['total'] = $entry;
            } elseif (!isset($result['primary'])) {
                $result['primary'] = $entry;
            }
        }

        $primary = $result['total'] ?? $result['night'] ?? $result['primary'] ?? null;
        if ($primary === null) {
            return null;
        }

        $result['raw'] = $primary['raw'];
        $result['value'] = $primary['value'];
        $result['currency'] = $primary['currency'];
        unset($result['primary']);

        return $result;
    }

    private function findPricesInText(string $text): array
    {
        $pattern = '/((?P<currency1>€|\$|£|CHF|EUR|USD|GBP)\s*(?P<amount1>[0-9][0-9.\s\x{00A0}]*[0-9](?:,[0-9]{2})?))|((?P<amount2>[0-9][0-9.\s\x{00A0}]*[0-9](?:,[0-9]{2})?)\s*(?P<currency2>€|EUR|CHF|USD|GBP|\$|£))/iu';
        if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $results = [];
        foreach ($matches[0] as $index => $fullMatch) {
            $raw = $fullMatch[0];
            $offset = $fullMatch[1];
            $currency = $matches['currency1'][$index][0] ?: $matches['currency2'][$index][0];
            $amount = $matches['amount1'][$index][0] ?: $matches['amount2'][$index][0];
            $normalized = $this->normalizeAmount($amount);
            if ($normalized === null) {
                continue;
            }
            $results[] = [
                'raw' => $raw,
                'offset' => $offset,
                'length' => strlen($raw),
                'currency' => $this->normalizeCurrency($currency),
                'value' => $normalized,
            ];
        }

        return $results;
    }

    private function classifyPriceContext(string $text, int $offset, int $length): array
    {
        $windowStart = max(0, $offset - 20);
        $windowEnd = min(strlen($text), $offset + $length + 20);
        $window = strtolower(substr($text, $windowStart, $windowEnd - $windowStart));
        $qualifier = null;

        if (str_contains($window, 'ab ')) {
            $qualifier = 'from';
        }

        if (str_contains($window, 'pro nacht') || str_contains($window, 'per night')) {
            return ['type' => 'night', 'qualifier' => $qualifier];
        }

        if (str_contains($window, 'gesamt') || str_contains($window, 'total')) {
            return ['type' => 'total', 'qualifier' => $qualifier];
        }

        return ['type' => null, 'qualifier' => $qualifier];
    }

    private function normalizeAmount(string $amount): ?float
    {
        $clean = str_replace(["\xc2\xa0", ' '], '', $amount);
        if ($clean === '') {
            return null;
        }

        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } elseif (str_contains($clean, ',')) {
            $clean = str_replace(',', '.', $clean);
        }

        if (!is_numeric($clean)) {
            return null;
        }

        return (float)$clean;
    }

    private function normalizeCurrency(string $currency): ?string
    {
        $currency = strtoupper(trim($currency));
        return self::CURRENCY_MAP[$currency] ?? null;
    }

    private function detectCurrency(string $text): ?string
    {
        foreach (self::CURRENCY_MAP as $symbol => $code) {
            if (str_contains($text, $symbol)) {
                return $code;
            }
        }

        return null;
    }

    private function decoratePriceResult(string $raw, float $value, ?string $currency): array
    {
        return [
            'raw' => $raw,
            'value' => $value,
            'currency' => $currency,
        ];
    }
}
