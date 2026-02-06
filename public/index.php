<?php

declare(strict_types=1);

const CONFIG_DIR = __DIR__ . '/../config';

require __DIR__ . '/../src/PriceParser.php';

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

function writeJson(string $path, array $payload): void
{
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Unable to encode JSON payload.');
    }

    if (file_put_contents($path, $encoded . PHP_EOL) === false) {
        throw new RuntimeException("Unable to write config file: {$path}");
    }
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

$errors = [];
$result = null;
$addedTargetId = null;
$urlInput = '';
$dateInput = '';
$addToMonitor = false;

try {
    $settings = readJson(CONFIG_DIR . '/settings.json');
    $targets = readJson(CONFIG_DIR . '/targets.json');
    $parser = new PriceParser($settings);
} catch (RuntimeException $exception) {
    $errors[] = $exception->getMessage();
    $settings = [];
    $targets = [];
    $parser = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $parser instanceof PriceParser) {
    $urlInput = trim((string)($_POST['url'] ?? ''));
    $dateInput = trim((string)($_POST['date'] ?? ''));
    $addToMonitor = isset($_POST['add_to_monitor']);

    if ($urlInput === '' || filter_var($urlInput, FILTER_VALIDATE_URL) === false) {
        $errors[] = 'Bitte eine gültige URL angeben.';
    }

    if ($dateInput !== '' && !isValidDate($dateInput)) {
        $errors[] = 'Bitte ein gültiges Datum im Format YYYY-MM-DD angeben.';
    }

    if ($errors === []) {
        $resolvedUrl = $parser->interpolateUrl($urlInput, $dateInput);

        try {
            $html = $parser->fetchPage($resolvedUrl);
        } catch (RuntimeException $exception) {
            $errors[] = $exception->getMessage();
            $html = null;
        }

        if ($html !== null) {
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

        if ($errors === [] && $addToMonitor) {
            $targetDate = $dateInput !== '' ? $dateInput : (new DateTimeImmutable('now'))->format('Y-m-d');
            $targetId = generateTargetId($urlInput, $targets, $targetDate);

            $targets[] = [
                'id' => $targetId,
                'url' => $urlInput,
                'date' => $targetDate,
                'rooms' => [
                    [
                        'name' => 'Gesamtpreis',
                        'room_hint' => 'Gesamtpreis',
                        'price_regex' => PriceParser::DEFAULT_TOTAL_REGEX,
                    ],
                ],
            ];

            try {
                writeJson(CONFIG_DIR . '/targets.json', $targets);
                $addedTargetId = $targetId;
            } catch (RuntimeException $exception) {
                $errors[] = $exception->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PreisMonitor</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 2rem;
            color: #1a1a1a;
        }
        form {
            max-width: 600px;
            display: grid;
            gap: 1rem;
            padding: 1.5rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #fafafa;
        }
        label {
            font-weight: bold;
        }
        input[type="text"],
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
    </style>
</head>
<body>
    <h1>PreisMonitor Web</h1>
    <form method="post">
        <div>
            <label for="url">URL</label>
            <input type="url" id="url" name="url" placeholder="https://example.com" value="<?= h($urlInput) ?>" required>
        </div>
        <div>
            <label for="date">Datum (optional)</label>
            <input type="date" id="date" name="date" value="<?= h($dateInput) ?>">
        </div>
        <div>
            <label>
                <input type="checkbox" name="add_to_monitor" <?= $addToMonitor ? 'checked' : '' ?>>
                In Preisüberwachung aufnehmen
            </label>
        </div>
        <button type="submit">Preis prüfen</button>
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

    <?php if ($result !== null): ?>
        <div class="notice">
            <strong>Ergebnis</strong>
            <ul class="result-list">
                <li>Gesamtpreis (raw): <?= h((string)$result['raw']) ?></li>
                <li>Gesamtpreis (numeric): <?= h((string)$result['value']) ?></li>
                <li class="meta">URL: <?= h((string)$result['url']) ?></li>
            </ul>
            <?php if ($addedTargetId !== null): ?>
                <p class="meta">Target gespeichert: <?= h($addedTargetId) ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</body>
</html>
