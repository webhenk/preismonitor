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

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function isValidDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if (!$parsed) {
        return false;
    }

    return $parsed->format('Y-m-d') === $date;
}

function generateTargetId(string $url, array $targets, ?string $date): string
{
    $parts = parse_url($url);
    $host = $parts['host'] ?? 'target';
    $path = $parts['path'] ?? '';
    $base = strtolower(trim($host . $path, '/'));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? 'target';
    $base = trim($base, '-');
    if ($base === '') {
        $base = 'target';
    }

    if ($date) {
        $base .= '-' . $date;
    }

    $existing = array_map(
        static fn(array $target): string => (string)($target['id'] ?? ''),
        $targets
    );

    $candidate = $base;
    $suffix = 1;
    while (in_array($candidate, $existing, true)) {
        $suffix++;
        $candidate = $base . '-' . $suffix;
    }

    return $candidate;
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

$errors = [];
$result = null;
$addedTargetId = null;
$urlInput = '';
$dateInput = '';
$addToMonitor = false;
$debugEnabled = false;
$debugInfo = null;
$debugSnippet = null;
$debugHintPos = null;
$debugHintLabel = null;
$actionMessage = null;

try {
    $settings = readJson(CONFIG_DIR . '/settings.json');
    $parser = new PriceParser($settings);
    $storage = new MonitorStorage(DATA_DIR);
    $monitors = $storage->getMonitors();
    $history = $storage->getHistory();
} catch (RuntimeException $exception) {
    $errors[] = $exception->getMessage();
    $parser = null;
    $storage = null;
    $monitors = [];
    $history = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $parser instanceof PriceParser && $storage instanceof MonitorStorage) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'analyze') {
        $urlInput = trim((string)($_POST['url'] ?? ''));
        $dateInput = trim((string)($_POST['date'] ?? ''));
        $addToMonitor = isset($_POST['add_to_monitor']);
        $debugEnabled = isset($_POST['debug']);

        if ($urlInput === '' || filter_var($urlInput, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'Bitte eine gültige URL angeben.';
        }

        if ($dateInput !== '' && !isValidDate($dateInput)) {
            $errors[] = 'Bitte ein gültiges Datum im Format YYYY-MM-DD angeben.';
        }

        if ($errors === []) {
            $resolvedUrl = $parser->interpolateUrl($urlInput, $dateInput);
            $usePlaywright = $parser->isRobinsonBookingUrl($resolvedUrl);

            try {
                if ($usePlaywright) {
                    $fetchResult = $parser->runPlaywrightRobinson($resolvedUrl);
                    $debugInfo = [
                        'requested_url' => $resolvedUrl,
                        'effective_url' => $fetchResult['url_effective'] ?? $resolvedUrl,
                        'status' => $fetchResult['http_status'] ?? 0,
                        'blocked' => $fetchResult['blocked'] ?? false,
                        'runner' => $fetchResult['runner'] ?? 'playwright',
                        'rendered_html_size' => $fetchResult['rendered_html_size'] ?? 0,
                        'xhr_hits' => $fetchResult['xhr_hits'] ?? 0,
                        'consent_clicked' => $fetchResult['consent_clicked'] ?? false,
                        'body_text_preview' => $fetchResult['body_text_preview'] ?? null,
                        'error' => $fetchResult['error'] ?? null,
                    ];

                    $httpStatus = (int)($fetchResult['http_status'] ?? 0);
                    if (!empty($fetchResult['error']) && $fetchResult['error'] !== 'did_not_render') {
                        $errors[] = 'URL konnte nicht geladen werden: ' . (string)$fetchResult['error'];
                    } elseif (!empty($fetchResult['blocked'])) {
                        $errors[] = 'URL konnte nicht geladen werden: blocked';
                    } elseif ($httpStatus >= 400) {
                        $errors[] = 'URL konnte nicht geladen werden: HTTP ' . (string)$httpStatus;
                    }

                    if ($errors === []) {
                        $priceInfo = $fetchResult['price'] ?? null;
                        if ($priceInfo === null) {
                            $errors[] = 'Kein Gesamtpreis gefunden. Bitte Seite prüfen.';
                        } else {
                            $result = [
                                'raw' => $priceInfo['raw'] ?? '',
                                'value' => $priceInfo['value'] ?? null,
                                'url' => $resolvedUrl,
                            ];
                        }
                    }
                } else {
                    $fetchResult = $parser->fetchPageWithInfo($resolvedUrl);
                    $html = $fetchResult['body'] ?? null;
                    $debugInfo = [
                        'requested_url' => $resolvedUrl,
                        'effective_url' => $fetchResult['effective_url'] ?? $resolvedUrl,
                        'status' => $fetchResult['status'] ?? 0,
                        'blocked' => $fetchResult['blocked'] ?? false,
                        'content_type' => $fetchResult['content_type'] ?? '',
                        'response_length' => $fetchResult['response_length'] ?? 0,
                        'response_preview' => $fetchResult['response_preview'] ?? null,
                        'title' => $fetchResult['title'] ?? null,
                        'response_path' => $fetchResult['response_path'] ?? null,
                        'size_download' => $fetchResult['size_download'] ?? 0.0,
                        'total_time' => $fetchResult['total_time'] ?? 0.0,
                        'error' => $fetchResult['error'] ?? null,
                    ];

                    if (!empty($fetchResult['error'])) {
                        $errors[] = 'URL konnte nicht geladen werden: ' . (string)$fetchResult['error'];
                    } elseif (!empty($fetchResult['blocked'])) {
                        $errors[] = 'URL konnte nicht geladen werden: blocked';
                    } elseif (($fetchResult['status'] ?? 0) >= 400) {
                        $errors[] = 'URL konnte nicht geladen werden: HTTP ' . (string)$fetchResult['status'];
                    } elseif ($html === null || $html === '') {
                        $errors[] = 'URL konnte nicht geladen werden: Leere Antwort.';
                    }

                    if ($debugEnabled && $html !== null && $html !== '') {
                        $hint = 'Gesamtpreis';
                        $debugHintPos = stripos($html, $hint);
                        if ($debugHintPos !== false) {
                            $debugSnippet = substr($html, max(0, $debugHintPos - 300), 900);
                        } else {
                            $debugSnippet = substr($html, 0, 900);
                        }
                        $debugSnippet = trim((string)preg_replace('/\s+/', ' ', $debugSnippet));
                    }

                    if ($errors === []) {
                        $priceInfo = $parser->extractTotalPrice($html);
                        if ($priceInfo === null) {
                            $errors[] = 'Kein Gesamtpreis gefunden. Bitte Regex oder Seite prüfen.';
                        } else {
                            $result = [
                                'raw' => $priceInfo['raw'] ?? '',
                                'value' => $priceInfo['value'] ?? null,
                                'url' => $resolvedUrl,
                            ];
                        }
                    }
                }
            } catch (RuntimeException $exception) {
                $errors[] = $exception->getMessage();
            }

            if ($errors === [] && $addToMonitor) {
                $targetDate = $dateInput !== '' ? $dateInput : (new DateTimeImmutable('now'))->format('Y-m-d');
                $targetId = generateTargetId($urlInput, $monitors, $targetDate);

                $monitors[] = [
                    'id' => $targetId,
                    'url' => $urlInput,
                    'date' => $targetDate,
                    'active' => true,
                    'price_regex' => PriceParser::DEFAULT_TOTAL_REGEX,
                    'created_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
                ];

                $storage->saveMonitors($monitors);
                $addedTargetId = $targetId;
                $actionMessage = 'Monitoring aktiviert.';
            }
        }
    }

    if ($action === 'toggle') {
        $targetId = (string)($_POST['target_id'] ?? '');
        foreach ($monitors as &$monitor) {
            if (($monitor['id'] ?? '') === $targetId) {
                $monitor['active'] = !($monitor['active'] ?? false);
                $actionMessage = $monitor['active'] ? 'Monitoring aktiviert.' : 'Monitoring pausiert.';
                break;
            }
        }
        unset($monitor);
        $storage->saveMonitors($monitors);
    }

    if ($action === 'remove') {
        $targetId = (string)($_POST['target_id'] ?? '');
        $monitors = array_values(array_filter(
            $monitors,
            static fn(array $monitor): bool => ($monitor['id'] ?? '') !== $targetId
        ));
        $storage->saveMonitors($monitors);
        $actionMessage = 'Monitoring entfernt.';
    }

    if ($action === 'run_now') {
        $monitors = runMonitorChecks($monitors, $parser, $storage);
        $history = $storage->getHistory();
        $actionMessage = 'Monitoring jetzt ausgeführt.';
    }
}

$historyByMonitor = [];
foreach ($history as $entry) {
    $historyByMonitor[$entry['id'] ?? ''][] = $entry;
}

foreach ($historyByMonitor as &$entries) {
    usort(
        $entries,
        static fn(array $a, array $b): int => strcmp((string)($b['checked_at'] ?? ''), (string)($a['checked_at'] ?? ''))
    );
}
unset($entries);
$isPlaywrightDebug = $debugInfo !== null && ($debugInfo['runner'] ?? '') === 'playwright';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PreisMonitor Web</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 2rem;
            color: #1a1a1a;
            background: #f8f9fb;
        }
        .card {
            max-width: 900px;
            margin-bottom: 2rem;
            padding: 1.5rem;
            border: 1px solid #ddd;
            border-radius: 10px;
            background: #fff;
        }
        form {
            display: grid;
            gap: 1rem;
        }
        label {
            font-weight: bold;
        }
        input[type="url"],
        input[type="date"] {
            width: 100%;
            padding: 0.5rem;
            border-radius: 4px;
            border: 1px solid #ccc;
        }
        button {
            padding: 0.6rem 1.2rem;
            background: #0057ff;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: fit-content;
        }
        .notice,
        .error {
            margin-top: 1.5rem;
            padding: 1rem;
            border-radius: 6px;
        }
        .notice {
            background: #eef5ff;
            border: 1px solid #bcd4ff;
        }
        .error {
            background: #ffecec;
            border: 1px solid #ffb3b3;
        }
        .result-list {
            margin: 0;
            padding-left: 1.2rem;
        }
        .meta {
            font-size: 0.9rem;
            color: #555;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th,
        td {
            border-bottom: 1px solid #eee;
            text-align: left;
            padding: 0.6rem 0.4rem;
            vertical-align: top;
        }
        .tag {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 999px;
            background: #f1f4ff;
            color: #2443a4;
            font-size: 0.8rem;
        }
        .row-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .debug {
            background: #f3f6ff;
            border: 1px dashed #8aa0e6;
        }
        .debug pre {
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 240px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #e0e4f7;
            padding: 0.6rem;
        }
    </style>
</head>
<body>
    <h1>PreisMonitor Web</h1>

    <div class="card">
        <h2>Preis analysieren</h2>
        <form method="post">
            <input type="hidden" name="action" value="analyze">
            <div>
                <label for="url">URL</label>
                <input type="url" id="url" name="url" placeholder="https://example.com" value="<?= h($urlInput) ?>" required>
            </div>
            <div>
                <label for="date">Datum (optional)</label>
                <input type="date" id="date" name="date" value="<?= h($dateInput) ?>">
            </div>
            <label>
                <input type="checkbox" name="add_to_monitor" <?= $addToMonitor ? 'checked' : '' ?>>
                Monitoring aktivieren (täglicher Check)
            </label>
            <label>
                <input type="checkbox" name="debug" <?= $debugEnabled ? 'checked' : '' ?>>
                Debug-Infos anzeigen
            </label>
            <button type="submit">Analyse</button>
        </form>

        <?php if ($errors !== []): ?>
            <div class="error">
                <strong>Fehler</strong>
                <ul class="result-list">
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($actionMessage !== null): ?>
            <div class="notice">
                <?= h($actionMessage) ?>
            </div>
        <?php endif; ?>

        <?php if ($result !== null): ?>
            <div class="notice">
                <strong>Ergebnis</strong>
                <ul class="result-list">
                    <li>Gesamtpreis (raw): <?= h((string)$result['raw']) ?></li>
                    <li>Gesamtpreis (numeric): <?= h((string)$result['value']) ?></li>
                    <li class="meta">URL: <?= h((string)$result['url']) ?></li>
                </ul>
                <?php if ($addedTargetId !== null): ?>
                    <p class="meta">Monitoring gespeichert: <?= h($addedTargetId) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($debugEnabled && $debugInfo !== null): ?>
            <div class="notice debug">
                <strong>Debug-Informationen</strong>
                <ul class="result-list">
                    <li>URL (angefragt): <?= h((string)$debugInfo['requested_url']) ?></li>
                    <li>URL (effektiv): <?= h((string)$debugInfo['effective_url']) ?></li>
                    <li>HTTP-Status: <?= h((string)$debugInfo['status']) ?></li>
                    <?php if ($isPlaywrightDebug): ?>
                        <li>Runner: <?= h((string)$debugInfo['runner']) ?></li>
                        <li>Rendered HTML size: <?= h((string)$debugInfo['rendered_html_size']) ?> bytes</li>
                        <li>XHR Hits: <?= h((string)$debugInfo['xhr_hits']) ?></li>
                        <li>Consent clicked: <?= h((string)($debugInfo['consent_clicked'] ? 'ja' : 'nein')) ?></li>
                    <?php else: ?>
                        <li>Content-Type: <?= h((string)$debugInfo['content_type']) ?></li>
                        <li>Response-Länge: <?= h((string)$debugInfo['response_length']) ?> bytes</li>
                        <li>Title: <?= h((string)($debugInfo['title'] ?? '—')) ?></li>
                        <li>Response gespeichert: <?= h((string)($debugInfo['response_path'] ?? '—')) ?></li>
                        <li>Antwortgröße: <?= h((string)round((float)$debugInfo['size_download'])) ?> bytes</li>
                        <li>Antwortzeit: <?= h((string)round((float)$debugInfo['total_time'], 3)) ?> s</li>
                    <?php endif; ?>
                    <li>Fehler: <?= h((string)($debugInfo['error'] ?? '—')) ?></li>
                    <?php if (!$isPlaywrightDebug): ?>
                        <li>Hinweistext "Gesamtpreis" gefunden: <?= $debugHintPos !== null && $debugHintPos !== false ? 'ja (Pos. ' . h((string)$debugHintPos) . ')' : 'nein' ?></li>
                    <?php endif; ?>
                </ul>
                <?php if (!$isPlaywrightDebug && !empty($debugInfo['response_preview'])): ?>
                    <strong>Erste 500 Zeichen</strong>
                    <pre><?= h((string)$debugInfo['response_preview']) ?></pre>
                <?php endif; ?>
                <?php if (!$isPlaywrightDebug && $debugSnippet !== null && $debugSnippet !== ''): ?>
                    <strong>HTML-Ausschnitt</strong>
                    <pre><?= h($debugSnippet) ?></pre>
                <?php endif; ?>
                <?php if ($isPlaywrightDebug && $result === null && !empty($debugInfo['body_text_preview'])): ?>
                    <strong>Body-Text Preview (1500 Zeichen)</strong>
                    <pre><?= h((string)$debugInfo['body_text_preview']) ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Monitoring Übersicht</h2>
        <form method="post" style="margin-bottom: 1rem;">
            <input type="hidden" name="action" value="run_now">
            <button type="submit">Monitoring jetzt ausführen</button>
        </form>

        <?php if ($monitors === []): ?>
            <p>Noch keine Monitoring-URLs angelegt.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>URL</th>
                        <th>Status</th>
                        <th>Letzter Check</th>
                        <th>Historie (letzte 5)</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($monitors as $monitor): ?>
                    <?php $entries = array_slice($historyByMonitor[$monitor['id']] ?? [], 0, 5); ?>
                    <tr>
                        <td>
                            <div><?= h((string)$monitor['url']) ?></div>
                            <div class="meta">Datum: <?= h((string)($monitor['date'] ?? '')) ?></div>
                        </td>
                        <td>
                            <?php if ($monitor['active'] ?? false): ?>
                                <span class="tag">aktiv</span>
                            <?php else: ?>
                                <span class="tag">pausiert</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= h((string)($monitor['last_checked_at'] ?? '—')) ?><br>
                            <?php if (!empty($monitor['last_value'])): ?>
                                <span class="meta">Letzter Preis: <?= h((string)$monitor['last_value']) ?></span>
                            <?php elseif (!empty($monitor['last_error'])): ?>
                                <span class="meta">Fehler: <?= h((string)$monitor['last_error']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($entries === []): ?>
                                <span class="meta">Noch keine Historie.</span>
                            <?php else: ?>
                                <ul class="result-list">
                                    <?php foreach ($entries as $entry): ?>
                                        <li>
                                            <?= h((string)($entry['checked_at'] ?? '')) ?>:
                                            <?php if (isset($entry['value'])): ?>
                                                <?= h((string)$entry['value']) ?>
                                            <?php else: ?>
                                                Fehler: <?= h((string)($entry['error'] ?? 'Unbekannt')) ?>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <form method="post">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="target_id" value="<?= h((string)$monitor['id']) ?>">
                                    <button type="submit"><?= ($monitor['active'] ?? false) ? 'Pausieren' : 'Aktivieren' ?></button>
                                </form>
                                <form method="post" onsubmit="return confirm('Monitoring wirklich entfernen?');">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="target_id" value="<?= h((string)$monitor['id']) ?>">
                                    <button type="submit" style="background: #999;">Entfernen</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>
