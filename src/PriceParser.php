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
    }
}
