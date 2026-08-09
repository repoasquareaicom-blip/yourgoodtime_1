<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Local Debug Database Configuration
|--------------------------------------------------------------------------
| Fill these in directly, or leave as null to read Backend/.env values.
| This file is intentionally standalone: no Laravel bootstrap, no routes.
*/
$dbConfig = [
    'driver' => null,
    'host' => null,
    'port' => null,
    'database' => null,
    'username' => null,
    'password' => null,
    'charset' => 'utf8mb4',
];

function loadLocalEnv(string $path): array
{
    if (! is_file($path)) {
        return [];
    }

    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim(trim($value), "\"'");
    }

    return $values;
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function titleText(string|int $key): string
{
    return ucwords(str_replace(['_', '-'], ' ', (string) $key));
}

function formatDateValue(mixed $value): ?string
{
    if (! is_string($value) && ! is_numeric($value)) {
        return null;
    }

    $text = trim((string) $value);
    $hasTime = preg_match('/(?:[T ]\d{2}:\d{2}(?::\d{2})?|\d{1,2}:\d{2}\s?(?:AM|PM))/i', $text) === 1;
    $dateOnly = preg_replace('/[T ].*$/', '', $text);

    $formats = [
        'Y-m-d',
        'Y/m/d',
        'd-m-Y',
        'd/m/Y',
        'd M Y',
        'd-M-Y',
        'd/m/y',
        'd-m-y',
    ];

    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat('!'.$format, $dateOnly);
        if ($date instanceof DateTimeImmutable) {
            if (! $hasTime) {
                return $date->format('d-M-Y');
            }

            try {
                return (new DateTimeImmutable($text))->format('d-M-Y h:i A');
            } catch (Throwable) {
                return $date->format('d-M-Y');
            }
        }
    }

    if (preg_match('/\d/', $text) !== 1 || preg_match('/[-\/T:]/', $text) !== 1) {
        return null;
    }

    try {
        $date = new DateTimeImmutable($text);

        return $hasTime ? $date->format('d-M-Y h:i A') : $date->format('d-M-Y');
    } catch (Throwable) {
        return null;
    }
}

function valueText(mixed $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }

    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '-';
    }

    return formatDateValue($value) ?? (string) $value;
}

function appKeyForSignature(array $env): string
{
    return (string) ($env['APP_KEY'] ?? '');
}

function validEmbeddedSignature(int $id, bool $embedded, int $expires, ?string $signature, array $env): bool
{
    if (! $embedded || $id < 1 || $expires < time() || ! is_string($signature) || $signature === '') {
        return false;
    }

    $payload = implode('|', [$id, 1, $expires]);
    $expected = hash_hmac('sha256', $payload, appKeyForSignature($env));

    return hash_equals($expected, $signature);
}

function decodeJson(mixed $json): array
{
    if ($json === null || $json === '') {
        return ['valid' => true, 'value' => null, 'pretty' => 'NULL'];
    }

    if (is_array($json)) {
        return [
            'valid' => true,
            'value' => $json,
            'pretty' => json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
        ];
    }

    $decoded = json_decode((string) $json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['valid' => false, 'value' => null, 'pretty' => 'Invalid JSON'];
    }

    return [
        'valid' => true,
        'value' => $decoded,
        'pretty' => json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'NULL',
    ];
}

function isAssoc(array $array): bool
{
    return $array !== [] && array_keys($array) !== range(0, count($array) - 1);
}

function findFirstByKeys(mixed $payload, array $keys): mixed
{
    if (! is_array($payload)) {
        return null;
    }

    foreach ($keys as $key) {
        if (array_key_exists($key, $payload)) {
            return $payload[$key];
        }
    }

    foreach ($payload as $value) {
        $found = findFirstByKeys($value, $keys);
        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

function normalizeRows(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    if (isset($value['data']) && is_array($value['data'])) {
        $value = $value['data'];
    }

    if (isAssoc($value)) {
        return [$value];
    }

    return array_values(array_filter($value, 'is_array'));
}

function planetIdNameMap(): array
{
    return [
        0 => 'Sun',
        1 => 'Moon',
        2 => 'Mars',
        3 => 'Mercury',
        4 => 'Jupiter',
        5 => 'Venus',
        6 => 'Saturn',
        7 => 'Rahu',
        8 => 'Ketu',
    ];
}

function planetNameFromId(mixed $planetId): ?string
{
    if ($planetId === null || $planetId === '') {
        return null;
    }

    $map = planetIdNameMap();
    $id = (int) $planetId;

    return array_key_exists($id, $map) ? $map[$id] : null;
}

function rowValueByKeys(array $row, array $keys): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }
    }

    $normalizedKeys = array_map('strtolower', $keys);
    foreach ($row as $key => $value) {
        if (in_array(strtolower((string) $key), $normalizedKeys, true)) {
            return $value;
        }
    }

    return null;
}

function displayRowValue(array $row, string|int $column): mixed
{
    $columnKey = strtolower((string) $column);

    if (in_array($columnKey, ['name', 'planet'], true)) {
        $planetName = planetNameFromId(rowValueByKeys($row, ['planet_id', 'id']));

        if ($planetName !== null) {
            return $planetName;
        }
    }

    return rowValueByKeys($row, [(string) $column]);
}

function normalizePlanetDisplayRows(array $rows): array
{
    foreach ($rows as $index => $row) {
        if (! is_array($row)) {
            continue;
        }

        $planetId = rowValueByKeys($row, ['planet_id', 'id']);
        $planetName = planetNameFromId($planetId);

        if ($planetName !== null) {
            $rows[$index]['name'] = $planetName;
        }
    }

    return $rows;
}

function normalizeDashaMajorDisplayRows(array $rows): array
{
    foreach ($rows as $index => $row) {
        if (! is_array($row)) {
            continue;
        }

        $planetId = rowValueByKeys($row, ['planet_id', 'id']);
        $planetName = planetNameFromId($planetId);

        if ($planetName !== null) {
            $rows[$index]['planet'] = $planetName;
        }
    }

    return $rows;
}

function renderDefinitionTable(array $data): void
{
    echo '<div class="table-responsive"><table class="table table-sm table-striped align-middle mb-0"><tbody>';
    foreach ($data as $key => $value) {
        echo '<tr><th class="w-25">'.h(titleText($key)).'</th><td>';
        if (is_array($value)) {
            renderGenericValue($value);
        } else {
            echo h(valueText($value));
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}

function renderCompactDefinitionGrid(array $data, int $columns = 4): void
{
    if ($data === []) {
        echo '<p class="text-muted mb-0">No data.</p>';
        return;
    }

    $columnClass = match ($columns) {
        2 => 'col-md-6',
        3 => 'col-md-4',
        default => 'col-md-6 col-xl-3',
    };

    echo '<div class="row g-2 compact-definition-grid">';
    foreach ($data as $key => $value) {
        echo '<div class="'.$columnClass.'">';
        echo '<div class="border rounded bg-white p-2 h-100">';
        echo '<div class="small text-uppercase text-muted fw-semibold">'.h(titleText($key)).'</div>';
        echo '<div class="fw-semibold text-break">';
        if (is_array($value)) {
            echo '<code>'.h(valueText($value)).'</code>';
        } else {
            echo h(valueText($value));
        }
        echo '</div></div></div>';
    }
    echo '</div>';
}

function renderRowsTable(array $rows, ?array $preferredColumns = null): void
{
    if ($rows === []) {
        echo '<p class="text-muted mb-0">No data.</p>';
        return;
    }

    $columns = $preferredColumns ?: [];
    if ($columns === []) {
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                if (! in_array($key, $columns, true)) {
                    $columns[] = $key;
                }
            }
        }
    }

    echo '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead><tr>';
    foreach ($columns as $column) {
        echo '<th>'.h(titleText($column)).'</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($columns as $column) {
            echo '<td>';
            $value = displayRowValue($row, $column);
            if (is_array($value)) {
                echo '<code>'.h(valueText($value)).'</code>';
            } else {
                echo h(valueText($value));
            }
            echo '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

function renderBadges(mixed $value): void
{
    $items = normalizeRows($value);

    if ($items === [] && is_array($value)) {
        $items = $value;
    }

    if (! is_array($items) || $items === []) {
        echo '<p class="text-muted mb-0">No data.</p>';
        return;
    }

    foreach ($items as $key => $item) {
        $text = is_array($item) ? ($item['name'] ?? $item['dosha'] ?? $item['type'] ?? json_encode($item)) : $item;
        echo '<span class="badge text-bg-warning me-2 mb-2">'.h(valueText($text ?: $key)).'</span>';
    }
}

function renderStrengthCards(mixed $value): void
{
    $rows = normalizeRows($value);

    if ($rows === []) {
        echo '<p class="text-muted mb-0">No data.</p>';
        return;
    }

    echo '<div class="row g-3">';
    foreach ($rows as $index => $row) {
        echo '<div class="col-md-6 col-xl-4"><div class="card h-100 shadow-sm"><div class="card-body">';
        echo '<h6 class="card-title">'.h(valueText($row['name'] ?? $row['planet'] ?? $row['type'] ?? 'Strength '.($index + 1))).'</h6>';
        renderDefinitionTable($row);
        echo '</div></div></div>';
    }
    echo '</div>';
}

function renderGenericValue(mixed $value): void
{
    if (! is_array($value)) {
        echo h(valueText($value));
        return;
    }

    if ($value === []) {
        echo '<span class="text-muted">No data.</span>';
        return;
    }

    if (isAssoc($value)) {
        renderDefinitionTable($value);
        return;
    }

    $rows = normalizeRows($value);
    if ($rows !== []) {
        renderRowsTable($rows);
        return;
    }

    echo '<code>'.h(valueText($value)).'</code>';
}

function renderAccordionItem(string $parentId, string $id, string $title, callable $renderer): void
{
    echo '<div class="accordion-item">';
    echo '<h2 class="accordion-header" id="'.$id.'-heading">';
    echo '<button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#'.$id.'" aria-expanded="false" aria-controls="'.$id.'">';
    echo h($title);
    echo '</button></h2>';
    echo '<div id="'.$id.'" class="accordion-collapse collapse" aria-labelledby="'.$id.'-heading" data-bs-parent="#'.$parentId.'">';
    echo '<div class="accordion-body">';
    $renderer();
    echo '</div></div></div>';
}

$env = loadLocalEnv(__DIR__.'/../.env');
$driver = $dbConfig['driver'] ?: ($env['DB_CONNECTION'] ?? 'mysql');
$host = $dbConfig['host'] ?: ($env['DB_HOST'] ?? '127.0.0.1');
$port = $dbConfig['port'] ?: ($env['DB_PORT'] ?? '3306');
$database = $dbConfig['database'] ?: ($env['DB_DATABASE'] ?? '');
$username = $dbConfig['username'] ?: ($env['DB_USERNAME'] ?? '');
$password = $dbConfig['password'] ?? ($env['DB_PASSWORD'] ?? '');
$charset = $dbConfig['charset'] ?: 'utf8mb4';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$embedded = filter_input(INPUT_GET, 'embedded', FILTER_VALIDATE_BOOLEAN);
$expires = filter_input(INPUT_GET, 'expires', FILTER_VALIDATE_INT) ?: 0;
$signature = filter_input(INPUT_GET, 'signature');
$requiresSignedLink = (($env['APP_ENV'] ?? 'production') !== 'local') || (bool) $embedded;
$record = null;
$error = null;

if ($id && (! $requiresSignedLink || validEmbeddedSignature((int) $id, true, (int) $expires, $signature, $env))) {
    try {
        $dsn = $driver === 'sqlite'
            ? 'sqlite:'.$database
            : "{$driver}:host={$host};port={$port};dbname={$database};charset={$charset}";

        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $statement = $pdo->prepare('SELECT * FROM prediction_requests WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $record = $statement->fetch() ?: null;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
} elseif ($id && $requiresSignedLink) {
    http_response_code(403);
    $error = 'This prediction viewer link is invalid or expired.';
}

$normalized = decodeJson($record['normalized_astrology_data'] ?? null);
$normalizedValue = is_array($normalized['value']) ? $normalized['value'] : [];
$birth = is_array($normalizedValue['birth'] ?? null) ? $normalizedValue['birth'] : [];
$identity = is_array($normalizedValue['identity'] ?? null) ? $normalizedValue['identity'] : [];
$chart = is_array($normalizedValue['chart'] ?? null) ? $normalizedValue['chart'] : [];
$planets = is_array($normalizedValue['planets'] ?? null) ? $normalizedValue['planets'] : [];
$dashaMajor = is_array($normalizedValue['dasha']['major'] ?? null) ? $normalizedValue['dasha']['major'] : [];

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prediction Response Viewer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7fb; }
        .navbar { box-shadow: 0 4px 18px rgba(15, 23, 42, .16); }
        .sticky-nav { position: sticky; top: 0; z-index: 1030; }
        .page-shell { max-width: 1320px; }
        .json-block {
            background: #0f172a;
            color: #dbeafe;
            border-radius: .75rem;
            padding: 1rem;
            overflow: auto;
            max-height: 640px;
            font-size: .875rem;
            line-height: 1.5;
        }
        .json-key { color: #93c5fd; }
        .json-string { color: #bbf7d0; }
        .json-number { color: #fde68a; }
        .json-bool { color: #fca5a5; }
        .section-card { border: 0; box-shadow: 0 10px 30px rgba(15, 23, 42, .08); }
        th { white-space: nowrap; }
        .embedded-view { background: #fffdf8; }
        .embedded-view .page-shell { max-width: none; }
        .embedded-view main.page-shell { padding: 10px !important; }
        .embedded-view .section-card { margin-bottom: 12px !important; }
        .embedded-view .navbar,
        .embedded-view .lookup-form { display: none !important; }
        .embedded-view .card-header { padding: .55rem .75rem; }
        .embedded-view .card-body { padding: .65rem; }
        .embedded-view .normalized-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: .75rem;
        }
        .embedded-view .normalized-grid > [class*="col-"] {
            width: auto;
            max-width: none;
            padding: 0;
        }
        .embedded-view .compact-definition-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: .5rem;
        }
        .embedded-view .compact-definition-grid > [class*="col-"] {
            width: auto;
            max-width: none;
            padding: 0;
        }
        .embedded-view .table-responsive {
            max-height: 420px;
            overflow: auto;
        }
        .embedded-view table {
            font-size: .78rem;
        }
        .embedded-view th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: #fff8ec;
        }
    </style>
</head>
<body class="<?= $embedded ? 'embedded-view' : '' ?>">
    <?php if (! $embedded): ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-nav">
        <div class="container-fluid page-shell">
            <a class="navbar-brand fw-semibold" href="#">
                <i class="bi bi-database-check me-2"></i>Prediction Response Viewer
            </a>
            <div class="navbar-nav flex-row gap-3">
                <a class="nav-link" href="#birth">Birth</a>
                <a class="nav-link" href="#identity">Identity</a>
                <a class="nav-link" href="#chart">Chart</a>
                <a class="nav-link" href="#planets">Planets</a>
                <a class="nav-link" href="#dasha">Dasha Major</a>
            </div>
        </div>
    </nav>
    <?php endif; ?>

    <main class="container-fluid page-shell py-4">
        <?php if (! $embedded): ?>
        <form class="card section-card mb-4 lookup-form" method="get">
            <div class="card-body d-flex flex-wrap align-items-end gap-3">
                <div>
                    <label class="form-label fw-semibold" for="id">Prediction Request ID</label>
                    <input class="form-control" id="id" type="number" name="id" min="1" value="<?= h($id ?: '') ?>" placeholder="14" required>
                </div>
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-search me-1"></i>Load Response
                </button>
                <span class="text-muted">Example: <code>?id=14</code></span>
            </div>
        </form>
        <?php endif; ?>

        <?php if (! $id): ?>
            <div class="alert alert-info">Enter an id or open <code>view-prediction-response.php?id=14</code>.</div>
        <?php elseif ($error): ?>
            <div class="alert alert-danger"><strong>Database Error:</strong> <?= h($error) ?></div>
        <?php elseif (! $record): ?>
            <div class="alert alert-warning fs-5">Prediction Request Not Found</div>
        <?php else: ?>
            <section id="normalized" class="card section-card mb-4">
                <div class="card-header bg-white">
                    <h2 class="h5 mb-0"><i class="bi bi-diagram-3 me-2"></i>Normalized Astrology Data</h2>
                </div>
                <div class="card-body">
                    <?php if (! $normalized['valid']): ?>
                        <div class="alert alert-danger">Invalid JSON</div>
                    <?php elseif ($normalizedValue === []): ?>
                        <p class="text-muted mb-0">No normalized astrology data.</p>
                    <?php else: ?>
                        <div class="row g-3 normalized-grid">
                            <div id="birth" class="col-12">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-header bg-light fw-semibold">Birth</div>
                                    <div class="card-body">
                                        <?php renderCompactDefinitionGrid($birth, 4); ?>
                                    </div>
                                </div>
                            </div>

                            <div id="identity" class="col-12">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-header bg-light fw-semibold">Identity</div>
                                    <div class="card-body">
                                        <?php renderCompactDefinitionGrid($identity, 4); ?>
                                    </div>
                                </div>
                            </div>

                            <div id="chart" class="col-12">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-header bg-light fw-semibold">Chart</div>
                                    <div class="card-body">
                                        <?php renderGenericValue($chart); ?>
                                    </div>
                                </div>
                            </div>

                            <div id="planets" class="col-12">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-header bg-light fw-semibold">Planets Table</div>
                                    <div class="card-body">
                                        <?php renderRowsTable(normalizePlanetDisplayRows(normalizeRows($planets))); ?>
                                    </div>
                                </div>
                            </div>

                            <div id="dasha" class="col-12">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-header bg-light fw-semibold">Dasha Major Table</div>
                                    <div class="card-body">
                                        <?php renderRowsTable(normalizeDashaMajorDisplayRows(normalizeRows($dashaMajor))); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
