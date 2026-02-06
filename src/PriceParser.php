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
    private const API_URL_PATTERN = '/(?:(?:https?:)?\/\/[^\s"\'<>]+|\/[a-z0-9_\-\/.]+\?(?:[^\s"\'<>]+)|\/[a-z0-9_\-\/.]*(?:api|graphql|offers|rates|prices)[^\s"\'<>]*)/i';

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

    public function fetchPage(string $url): array
    {
        $result = $this->fetchPageWithInfo($url);
        $error = $result['error'];
        $status = (int)($result['status'] ?? 0);
        $body = $result['body'];
        $blocked = (bool)($result['blocked'] ?? false);

        if ($blocked) {
            $result['state'] = 'blocked';
        } elseif ($error !== null) {
            $result['state'] = 'error';
        } elseif ($status >= 400) {
            $result['state'] = 'http_error';
        } elseif ($body === null || $body === '') {
            $result['state'] = 'empty';
        } else {
            $result['state'] = 'ok';
        }

        return $result;
    }

    public function fetchPageWithInfo(string $url): array
    {
        $userAgent = $this->settings['user_agent'] ?? 'PreisMonitor/1.0 (+https://example.com)';
        $timeout = (int)($this->settings['timeout_seconds'] ?? 20);
        $baseDir = dirname(__DIR__);
        $debugDir = $baseDir . '/artifacts/debug';
        $cookieDir = $debugDir . '/cookies';
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? 'unknown-host';
        $safeHost = preg_replace('/[^a-z0-9.-]+/i', '-', $host) ?? 'unknown-host';
        $timestamp = (new DateTimeImmutable('now'))->format('Ymd_His');
        $responseFileName = sprintf('%s_%s.html', $safeHost, $timestamp);
        $responseFilePath = $debugDir . '/' . $responseFileName;
        $responseFileRelative = 'artifacts/debug/' . $responseFileName;
        $cookieFile = $cookieDir . '/' . $safeHost . '.txt';

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException("Unable to initialize curl for {$url}");
        }

        if (!is_dir($cookieDir) && !mkdir($cookieDir, 0775, true) && !is_dir($cookieDir)) {
            throw new RuntimeException('Unable to create debug cookie directory.');
        }

        if (!is_dir($debugDir) && !mkdir($debugDir, 0775, true) && !is_dir($debugDir)) {
            throw new RuntimeException('Unable to create debug directory.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: de-DE,de;q=0.9,en;q=0.8',
            ],
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

        $blocked = false;
        if ($response !== false && $response !== null && $response !== '') {
            $blocked = $this->isBlockedHtml($response);
        $responseLength = $response === false ? 0 : strlen($response);
        $responsePreview = $response === false ? null : substr($response, 0, 500);
        $title = null;
        if ($response !== false && preg_match('/<title[^>]*>(.*?)<\/title>/is', $response, $matches)) {
            $title = trim((string)preg_replace('/\s+/', ' ', $matches[1]));
        }

        $responsePath = null;
        if ($response !== false) {
            if (file_put_contents($responseFilePath, $response) !== false) {
                $responsePath = $responseFileRelative;
            }
        }

        return [
            'body' => $response === false ? null : $response,
            'error' => $response === false ? $error : null,
            'status' => $status,
            'content_type' => $contentType,
            'total_time' => $totalTime,
            'size_download' => $sizeDownload,
            'effective_url' => $effectiveUrl,
            'blocked' => $blocked,
            'response_length' => $responseLength,
            'response_preview' => $responsePreview,
            'title' => $title,
            'response_path' => $responsePath,
        ];
    }

    public function isBlockedHtml(string $html): bool
    {
        $signals = [
            'captcha',
            'access denied',
            'enable javascript',
            'verify you are human',
            'unusual traffic',
            'bot detection',
            'attention required',
        ];

        foreach ($signals as $signal) {
            if (stripos($html, $signal) !== false) {
                return true;
            }
        }

        return false;
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

    public function parseApiPhase(string $html, string $baseUrl): array
    {
        $requests = $this->discoverApiRequests($html, $baseUrl);
        if ($requests === []) {
            return [];
        }

        $debug = [
            'base_url' => $baseUrl,
            'requests' => [],
        ];
        $offers = [];

        foreach ($requests as $request) {
            $result = $this->fetchApiRequest($request);
            $debugEntry = [
                'request' => $request,
                'response' => $result,
            ];

            if ($result['error'] === null && $result['body'] !== null) {
                $decoded = $this->decodeJsonResponse($result['body']);
                if ($decoded !== null) {
                    $extracted = $this->extractOffersFromJson($decoded);
                    if ($extracted !== []) {
                        $offers = array_merge($offers, $extracted);
                        $debugEntry['offers'] = $extracted;
                    }
                }
            }

            $debug['requests'][] = $debugEntry;
        }

        $this->writeDebugArtifact($baseUrl, $debug);

        return $offers;
    }

    private function discoverApiRequests(string $html, string $baseUrl): array
    {
        $requests = [];
        $urls = [];

        foreach ($this->extractUrlsFromHtml($html) as $url) {
            $resolved = $this->resolveUrl($url, $baseUrl);
            if ($resolved !== null) {
                $urls[$resolved] = true;
            }
        }

        foreach ($this->extractRequestConfigsFromJson($html, $baseUrl) as $request) {
            if (isset($request['url'])) {
                $urls[$request['url']] = true;
            }
            $requests[] = $request;
        }

        foreach (array_keys($urls) as $url) {
            $requests[] = [
                'url' => $url,
                'method' => 'GET',
                'headers' => [],
                'cookies' => [],
                'query' => [],
            ];
        }

        $unique = [];
        foreach ($requests as $request) {
            if (!isset($request['url'])) {
                continue;
            }
            $key = md5(json_encode($request));
            $unique[$key] = $request;
        }

        return array_values($unique);
    }

    private function extractUrlsFromHtml(string $html): array
    {
        $urls = [];

        if (preg_match_all(self::API_URL_PATTERN, $html, $matches)) {
            foreach ($matches[0] as $match) {
                $urls[] = trim($match, " \t\n\r\0\x0B\"'<>\");
            }
        }

        if (preg_match_all('/data-[a-z0-9_-]+\s*=\s*(["\'])(.*?)\1/i', $html, $dataMatches)) {
            foreach ($dataMatches[2] as $value) {
                if (preg_match(self::API_URL_PATTERN, $value)) {
                    $urls[] = $value;
                }
            }
        }

        if (preg_match_all('/"(?:url|endpoint|api|href)"\s*:\s*"([^"]+)"/i', $html, $urlMatches)) {
            foreach ($urlMatches[1] as $value) {
                if (preg_match(self::API_URL_PATTERN, $value)) {
                    $urls[] = $value;
                }
            }
        }

        $unique = [];
        foreach ($urls as $url) {
            $unique[$url] = true;
        }

        return array_keys($unique);
    }

    private function extractRequestConfigsFromJson(string $html, string $baseUrl): array
    {
        $requests = [];

        if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $block) {
                $decoded = $this->decodeJsonResponse($block);
                if ($decoded !== null) {
                    $requests = array_merge($requests, $this->discoverRequestsFromData($decoded, $baseUrl));
                }
            }
        }

        if (preg_match_all('/<script[^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $block) {
                $decoded = $this->decodeJsonResponse($block);
                if ($decoded !== null) {
                    $requests = array_merge($requests, $this->discoverRequestsFromData($decoded, $baseUrl));
                }
            }
        }

        return $requests;
    }

    private function discoverRequestsFromData($data, string $baseUrl): array
    {
        $requests = [];

        if (is_array($data)) {
            $isAssoc = array_keys($data) !== range(0, count($data) - 1);
            if ($isAssoc) {
                $urlKey = $data['url'] ?? $data['endpoint'] ?? $data['api'] ?? null;
                if (is_string($urlKey)) {
                    $resolved = $this->resolveUrl($urlKey, $baseUrl);
                    if ($resolved !== null) {
                        $requests[] = [
                            'url' => $resolved,
                            'method' => $data['method'] ?? 'GET',
                            'headers' => $this->normalizeHeaderArray($data['headers'] ?? []),
                            'cookies' => $this->normalizeCookieArray($data['cookies'] ?? ($data['cookie'] ?? [])),
                            'query' => $data['query'] ?? ($data['params'] ?? []),
                        ];
                    }
                }
            }

            foreach ($data as $value) {
                $requests = array_merge($requests, $this->discoverRequestsFromData($value, $baseUrl));
            }
        }

        return $requests;
    }

    private function fetchApiRequest(array $request): array
    {
        $url = $request['url'] ?? null;
        if (!$url) {
            return [
                'body' => null,
                'error' => 'Missing URL',
                'status' => 0,
                'content_type' => null,
                'effective_url' => null,
            ];
        }

        $method = strtoupper((string)($request['method'] ?? 'GET'));
        $headers = $request['headers'] ?? [];
        $cookies = $request['cookies'] ?? [];
        $query = $request['query'] ?? [];

        if (is_array($query) && $query !== []) {
            $url = $this->appendQuery($url, $query);
        }

        $userAgent = $this->settings['user_agent'] ?? 'PreisMonitor/1.0 (+https://example.com)';
        $timeout = (int)($this->settings['timeout_seconds'] ?? 20);

        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'body' => null,
                'error' => "Unable to initialize curl for {$url}",
                'status' => 0,
                'content_type' => null,
                'effective_url' => null,
            ];
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $headerLines[] = $key . ': ' . $value;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
        ];

        if ($headerLines !== []) {
            $options[CURLOPT_HTTPHEADER] = $headerLines;
        }

        if ($cookies !== []) {
            $cookiePairs = [];
            foreach ($cookies as $key => $value) {
                $cookiePairs[] = $key . '=' . $value;
            }
            $options[CURLOPT_COOKIE] = implode('; ', $cookiePairs);
        }

        if ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        $status = (int)($info['http_code'] ?? 0);
        $contentType = (string)($info['content_type'] ?? '');
        $effectiveUrl = (string)($info['url'] ?? $url);
        curl_close($ch);

        return [
            'body' => $response === false ? null : $response,
            'error' => $response === false ? $error : null,
            'status' => $status,
            'content_type' => $contentType,
            'effective_url' => $effectiveUrl,
        ];
    }

    private function decodeJsonResponse(string $payload): ?array
    {
        $trimmed = trim($payload);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($trimmed, '{');
        if ($start === false) {
            $start = strpos($trimmed, '[');
        }
        if ($start === false) {
            return null;
        }

        $substring = substr($trimmed, $start);
        $decoded = json_decode($substring, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return null;
    }

    private function extractOffersFromJson(array $data, string $path = ''): array
    {
        $offers = [];

        foreach ($data as $key => $value) {
            $currentPath = $path === '' ? (string)$key : $path . '.' . $key;
            if (is_array($value)) {
                $offers = array_merge($offers, $this->extractOffersFromJson($value, $currentPath));
                continue;
            }

            if (!is_numeric($value) && !is_string($value)) {
                continue;
            }

            $priceValue = null;
            if (in_array((string)$key, ['rate', 'price', 'total', 'amount', 'value', 'offer'], true)) {
                $priceValue = $this->normalizePriceValue($value);
            }

            if ($priceValue !== null) {
                $offers[] = [
                    'path' => $currentPath,
                    'raw' => $value,
                    'value' => $priceValue,
                ];
            }
        }

        return $offers;
    }

    private function normalizePriceValue($value): ?float
    {
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
        $normalized = str_replace(['.', ' '], ['', ''], $normalized);
        $normalized = str_replace(',', '.', $normalized);

        return (float)$normalized;
    }

    private function normalizeHeaderArray($headers): array
    {
        if (!is_array($headers)) {
            return [];
        }

        $normalized = [];
        foreach ($headers as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $normalized[$key] = is_string($value) ? $value : json_encode($value);
        }

        return $normalized;
    }

    private function normalizeCookieArray($cookies): array
    {
        if (!is_array($cookies)) {
            return [];
        }

        $normalized = [];
        foreach ($cookies as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $normalized[$key] = is_string($value) ? $value : json_encode($value);
        }

        return $normalized;
    }

    private function appendQuery(string $url, array $query): string
    {
        $parts = parse_url($url);
        $existing = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $existing);
        }

        $merged = array_merge($existing, $query);
        $queryString = http_build_query($merged);
        $base = $this->buildBaseUrl($parts);

        return $queryString ? $base . '?' . $queryString : $base;
    }

    private function buildBaseUrl(array $parts): string
    {
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';

        return $scheme . '://' . $host . $port . $path;
    }

    private function resolveUrl(string $url, string $baseUrl): ?string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $trimmed)) {
            return $trimmed;
        }

        $base = parse_url($baseUrl);
        if (!$base || empty($base['host'])) {
            return null;
        }

        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'];
        $port = isset($base['port']) ? ':' . $base['port'] : '';

        if (str_starts_with($trimmed, '//')) {
            return $scheme . ':' . $trimmed;
        }

        if (str_starts_with($trimmed, '/')) {
            return $scheme . '://' . $host . $port . $trimmed;
        }

        $path = $base['path'] ?? '/';
        $dir = rtrim(substr($path, 0, strrpos($path, '/') ?: 0), '/');
        $dir = $dir === '' ? '' : '/' . $dir;

        return $scheme . '://' . $host . $port . $dir . '/' . $trimmed;
    }

    private function writeDebugArtifact(string $baseUrl, array $payload): void
    {
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: 'unknown';
        $timestamp = (new DateTimeImmutable('now'))->format('Ymd_His');
        $directory = __DIR__ . '/../artifacts/debug';

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return;
        }

        $path = $directory . '/' . $host . '_' . $timestamp . '.json';
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }

        file_put_contents($path, $encoded);
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
