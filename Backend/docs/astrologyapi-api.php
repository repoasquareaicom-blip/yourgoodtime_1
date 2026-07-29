<?php

declare(strict_types=1);

/**
 * JSON bridge for AstrologyAPI (https://astrologyapi.com).
 * Auth: x-astrologyapi-key header (Access Token). See access-token usage guide.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED);
ob_start();

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (headers_sent()) {
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Server error: ' . ($error['message'] ?? 'unknown fatal error'),
    ], JSON_UNESCAPED_UNICODE);
});

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ('OPTIONS' === ($_SERVER['REQUEST_METHOD'] ?? '')) {
    http_response_code(204);
    exit;
}

require __DIR__ . '/astrologyapi/config.php';
require __DIR__ . '/astrologyapi/database.php';

$predictionAutoload = __DIR__ . '/astrologyapi/vendor/autoload.php';
if (is_file($predictionAutoload)) {
    require $predictionAutoload;
}
require __DIR__ . '/astrologyapi/prediction_excel.php';

function jsonResponse(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}

function getAccessToken(): string
{
    $env = getenv('ASTROLOGYAPI_ACCESS_TOKEN');
    if (is_string($env) && '' !== $env) {
        return $env;
    }

    return ASTROLOGYAPI_ACCESS_TOKEN;
}

function timezoneToOffset(string $timezone): float
{
    static $map = [
        'Asia/Kolkata' => 5.5,
        'Asia/Colombo' => 5.5,
        'Asia/Kathmandu' => 5.75,
        'Asia/Dhaka' => 6.0,
        'UTC' => 0.0,
        'America/New_York' => -5.0,
        'America/Los_Angeles' => -8.0,
    ];

    if (isset($map[$timezone])) {
        return $map[$timezone];
    }

    try {
        $dt = new DateTimeImmutable('now', new DateTimeZone($timezone));

        return $dt->getOffset() / 3600;
    } catch (Exception) {
        return 5.5;
    }
}

function parseBirthInput(array $input): array
{
    $datetime = trim((string)($input['datetime'] ?? ''));
    $timezone = trim((string)($input['timezone'] ?? 'Asia/Kolkata'));
    $latitude = $input['latitude'] ?? null;
    $longitude = $input['longitude'] ?? null;

    if ('' === $datetime) {
        throw new InvalidArgumentException('datetime is required (e.g. 1992-10-25T14:30:00).');
    }
    if (!is_numeric($latitude) || !is_numeric($longitude)) {
        throw new InvalidArgumentException('latitude and longitude are required.');
    }

    $dt = new DateTimeImmutable($datetime);

    return [
        'day' => (int)$dt->format('j'),
        'month' => (int)$dt->format('n'),
        'year' => (int)$dt->format('Y'),
        'hour' => (int)$dt->format('G'),
        'min' => (int)$dt->format('i'),
        'lat' => round((float)$latitude, 4),
        'lon' => round((float)$longitude, 4),
        'tzone' => timezoneToOffset($timezone),
    ];
}

function astrologyApiRequest(string $endpoint, array $body): array
{
    $token = getAccessToken();
    if ('' === $token || str_starts_with($token, 'ak-YOUR')) {
        throw new RuntimeException('AstrologyAPI access token is not configured.');
    }

    $url = rtrim(ASTROLOGYAPI_BASE_URL, '/') . '/' . ltrim($endpoint, '/');
    $ch = curl_init($url);
    if (false === $ch) {
        throw new RuntimeException('Could not initialize HTTP client.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-astrologyapi-key: ' . $token,
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (false === $raw) {
        throw new RuntimeException('API request failed: ' . ($error ?: 'curl error ' . $errno));
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid API response (HTTP ' . $httpCode . ').');
    }

    if ($httpCode >= 400) {
        $message = $decoded['msg'] ?? $decoded['message'] ?? $decoded['error'] ?? 'API error HTTP ' . $httpCode;
        if (is_array($message)) {
            $message = json_encode($message);
        }

        throw new RuntimeException((string)$message);
    }

    return $decoded;
}

function extractChartSvg(array $decoded): string
{
    if (isset($decoded['svg']) && is_string($decoded['svg'])) {
        return $decoded['svg'];
    }

    if (isset($decoded['data']['svg']) && is_string($decoded['data']['svg'])) {
        return $decoded['data']['svg'];
    }

    throw new RuntimeException('Chart image response did not include SVG.');
}

function normalizeDashaLord(string $planet): string
{
    return strtolower(preg_replace('/[^a-z]/i', '', $planet));
}

function readInput(): array
{
    if ('GET' === ($_SERVER['REQUEST_METHOD'] ?? '')) {
        return $_GET;
    }

    $raw = file_get_contents('php://input');
    if (!$raw) {
        return $_POST;
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

function parseChartOptions(array $input): array
{
    $chartId = preg_replace('/[^A-Za-z0-9]/', '', (string)($input['chart_id'] ?? 'D1')) ?: 'D1';
    $chartStyle = trim((string)($input['chart_style'] ?? 'south'));
    if (!in_array($chartStyle, ['north', 'south', 'east'], true)) {
        $chartStyle = 'south';
    }

    return ['chart_id' => $chartId, 'chart_style' => $chartStyle];
}

function parseReportMeta(array $input, array $birth): array
{
    $charts = parseChartOptions($input);

    return [
        'datetime' => trim((string)($input['datetime'] ?? '')),
        'timezone' => trim((string)($input['timezone'] ?? 'Asia/Kolkata')),
        'latitude' => $birth['lat'],
        'longitude' => $birth['lon'],
        'place_name' => trim((string)($input['place_name'] ?? '')),
        'chart_id' => $charts['chart_id'],
        'chart_style' => $charts['chart_style'],
    ];
}

function getPredictionDataDir(): string
{
    if (defined('PREDICTION_DATA_DIR') && '' !== PREDICTION_DATA_DIR) {
        return PREDICTION_DATA_DIR;
    }

    return __DIR__ . '/data';
}

function parsePredictionDataFilename(string $filename): ?array
{
    if (!preg_match('/\.xlsx?$/i', $filename)) {
        return null;
    }

    $base = (string)preg_replace('/\.xlsx?$/i', '', $filename);
    if (preg_match('/^\d+$/', $base)) {
        return [
            'filename' => $filename,
            'type' => 'single',
            'age' => (int)$base,
        ];
    }

    if (preg_match('/^(\d+)_(\d+)$/', $base, $matches)) {
        return [
            'filename' => $filename,
            'type' => 'range',
            'minAge' => (int)$matches[1],
            'maxAge' => (int)$matches[2],
        ];
    }

    return null;
}

function listPredictionDataFiles(): array
{
    $dir = getPredictionDataDir();
    if (!is_dir($dir)) {
        return [];
    }

    $files = [];
    foreach (scandir($dir) ?: [] as $entry) {
        if ('.' === $entry || '..' === $entry) {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($path)) {
            continue;
        }

        $parsed = parsePredictionDataFilename($entry);
        if (null !== $parsed) {
            $files[] = $parsed;
        }
    }

    usort($files, static fn (array $a, array $b): int => strcmp($a['filename'], $b['filename']));

    $deduped = [];
    foreach ($files as $file) {
        $base = (string)preg_replace('/\.xlsx?$/i', '', $file['filename']);
        $existing = $deduped[$base] ?? null;
        if (
            null === $existing
            || str_ends_with(strtolower($file['filename']), '.xlsx')
        ) {
            $deduped[$base] = $file;
        }
    }

    $files = array_values($deduped);
    usort($files, static fn (array $a, array $b): int => strcmp($a['filename'], $b['filename']));

    return $files;
}

function fetchVimshottariBundle(array $birth): array
{
    $current = astrologyApiRequest('current_vdasha', $birth);
    $major = astrologyApiRequest('major_vdasha', $birth);
    $mahaLord = normalizeDashaLord((string)($current['major']['planet'] ?? ''));
    $minorLord = normalizeDashaLord((string)($current['minor']['planet'] ?? ''));

    $antardasha = [] === $mahaLord
        ? []
        : astrologyApiRequest('sub_vdasha/' . $mahaLord, $birth);

    $pratyantardasha = [];
    if ('' !== $mahaLord && '' !== $minorLord) {
        $pratyantardasha = astrologyApiRequest(
            'sub_sub_vdasha/' . $mahaLord . '/' . $minorLord,
            $birth
        );
    }

    return [
        'current' => $current,
        'major' => $major,
        'antardasha' => $antardasha,
        'pratyantardasha' => $pratyantardasha,
        'mahaDashaLord' => $mahaLord,
        'antarDashaLord' => $minorLord,
    ];
}

function fetchFullReport(array $input, array $birth, array $meta): array
{
    $charts = parseChartOptions($input);
    $chartId = $charts['chart_id'];
    $chartStyle = $charts['chart_style'];
    $imageType = trim((string)($input['image_type'] ?? 'svg'));
    if (!in_array($imageType, ['svg', 'png'], true)) {
        $imageType = 'svg';
    }

    $chartImagePayload = array_merge($birth, [
        'chartType' => $chartStyle,
        'image_type' => $imageType,
    ]);
    $chartImageRaw = astrologyApiRequest('horo_chart_image/' . $chartId, $chartImagePayload);

    return [
        'location' => [
            'name' => $meta['place_name'],
            'lat' => $meta['latitude'],
            'lon' => $meta['longitude'],
        ],
        'chartImage' => [
            'chartId' => $chartId,
            'chartStyle' => $chartStyle,
            'imageType' => $imageType,
            'svg' => extractChartSvg($chartImageRaw),
            'data' => $chartImageRaw,
        ],
        'chartData' => [
            'chartId' => $chartId,
            'data' => astrologyApiRequest('horo_chart/' . $chartId, $birth),
        ],
        'planets' => [
            'data' => astrologyApiRequest('planets', $birth),
        ],
        'astroDetails' => [
            'data' => astrologyApiRequest('astro_details', $birth),
        ],
        'vimshottari' => fetchVimshottariBundle($birth),
    ];
}

$input = readInput();
$action = trim((string)($input['action'] ?? ''));

try {
    if ('config' === $action) {
        $token = getAccessToken();
        $configured = '' !== $token && !str_starts_with($token, 'ak-YOUR');
        $dbReady = false;
        try {
            getAstroPdo();
            $dbReady = true;
        } catch (Throwable) {
            $dbReady = false;
        }

        jsonResponse([
            'ok' => true,
            'configured' => $configured,
            'dbReady' => $dbReady,
            'provider' => 'AstrologyAPI',
        ]);
    }

    if ('list_reports' === $action) {
        $limit = (int)($input['limit'] ?? 20);
        jsonResponse([
            'ok' => true,
            'action' => $action,
            'reports' => array_map('formatReportRowSummary', listRecentReports($limit)),
        ]);
    }

    if ('get_report' === $action) {
        $id = (int)($input['id'] ?? 0);
        if ($id < 1) {
            throw new InvalidArgumentException('id is required to load a saved report.');
        }

        $row = findReportById($id);
        if (!$row) {
            jsonResponse(['ok' => false, 'error' => 'Saved report not found.'], 404);
        }

        jsonResponse(buildReportResponse($row, decodeStoredReport($row), true));
    }

    if ('delete_report' === $action) {
        $id = (int)($input['id'] ?? 0);
        if ($id < 1) {
            throw new InvalidArgumentException('id is required to delete a saved report.');
        }

        if (!deleteReportById($id)) {
            jsonResponse(['ok' => false, 'error' => 'Saved report not found.'], 404);
        }

        jsonResponse([
            'ok' => true,
            'action' => $action,
            'deletedId' => $id,
        ]);
    }

    if ('delete_all_reports' === $action) {
        $deleted = deleteAllReports();
        jsonResponse([
            'ok' => true,
            'action' => $action,
            'deletedCount' => $deleted,
        ]);
    }

    if ('list_prediction_data' === $action) {
        jsonResponse([
            'ok' => true,
            'action' => $action,
            'dataDir' => getPredictionDataDir(),
            'files' => listPredictionDataFiles(),
        ]);
    }

    if ('lookup_prediction_codes' === $action) {
        if (!is_file($predictionAutoload)) {
            throw new RuntimeException(
                'Excel reader not installed. Run: composer install in astrologyapi folder.'
            );
        }

        @set_time_limit(120);
        @ini_set('memory_limit', '256M');

        $items = $input['items'] ?? [];
        if (!is_array($items) || [] === $items) {
            throw new InvalidArgumentException('items array is required for lookup_prediction_codes.');
        }

        jsonResponse([
            'ok' => true,
            'action' => $action,
            'results' => lookupPredictionCodesBatch($items),
        ]);
    }

    if ('lookup_nakshatra_lagnam_code' === $action) {
        if (!is_file($predictionAutoload)) {
            throw new RuntimeException(
                'Excel reader not installed. Run: composer install in astrologyapi folder.'
            );
        }

        $lagnam = trim((string)($input['lagnam'] ?? ''));
        $rasi = trim((string)($input['rasi'] ?? ''));
        $nakshatra = trim((string)($input['nakshatra'] ?? ''));

        if ('' === $lagnam || '' === $rasi || '' === $nakshatra) {
            throw new InvalidArgumentException('lagnam, rasi and nakshatra are required.');
        }

        $result = lookupNakshatraLagnamVideoCode($lagnam, $rasi, $nakshatra);
        jsonResponse([
            'ok' => true,
            'action' => $action,
            'result' => $result,
        ]);
    }

    if ('' === $action) {
        jsonResponse([
            'ok' => false,
            'error' => 'Missing action. Use: config, report, get_report, list_reports, delete_report, delete_all_reports, list_prediction_data, lookup_prediction_codes, lookup_nakshatra_lagnam_code, planets, astro_details, horo_chart, horo_chart_image, vimshottari.',
        ], 400);
    }

    $birth = parseBirthInput($input);

    switch ($action) {
        case 'report':
            $meta = parseReportMeta($input, $birth);
            $forceRefresh = filter_var($input['force_refresh'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $cacheKey = buildReportCacheKey($meta);

            if (!$forceRefresh) {
                $cached = findReportByCacheKey($cacheKey);
                if ($cached) {
                    jsonResponse(buildReportResponse($cached, decodeStoredReport($cached), true));
                }
            }

            $report = fetchFullReport($input, $birth, $meta);
            $row = saveReport($meta, $report);
            jsonResponse(buildReportResponse($row, $report, false));
            break;

        case 'planets':
            jsonResponse([
                'ok' => true,
                'action' => $action,
                'data' => astrologyApiRequest('planets', $birth),
            ]);
            break;

        case 'astro_details':
            jsonResponse([
                'ok' => true,
                'action' => $action,
                'data' => astrologyApiRequest('astro_details', $birth),
            ]);
            break;

        case 'horo_chart':
            $chartId = preg_replace('/[^A-Za-z0-9]/', '', (string)($input['chart_id'] ?? 'D1')) ?: 'D1';
            jsonResponse([
                'ok' => true,
                'action' => $action,
                'chartId' => $chartId,
                'data' => astrologyApiRequest('horo_chart/' . $chartId, $birth),
            ]);
            break;

        case 'horo_chart_image':
            $chartId = preg_replace('/[^A-Za-z0-9]/', '', (string)($input['chart_id'] ?? 'D1')) ?: 'D1';
            $chartStyle = trim((string)($input['chart_style'] ?? 'south'));
            if (!in_array($chartStyle, ['north', 'south', 'east'], true)) {
                $chartStyle = 'south';
            }
            $imageType = trim((string)($input['image_type'] ?? 'svg'));
            if (!in_array($imageType, ['svg', 'png'], true)) {
                $imageType = 'svg';
            }
            $payload = array_merge($birth, [
                'chartType' => $chartStyle,
                'image_type' => $imageType,
            ]);
            $raw = astrologyApiRequest('horo_chart_image/' . $chartId, $payload);
            jsonResponse([
                'ok' => true,
                'action' => $action,
                'chartId' => $chartId,
                'chartStyle' => $chartStyle,
                'imageType' => $imageType,
                'svg' => extractChartSvg($raw),
                'data' => $raw,
            ]);
            break;

        case 'current_vdasha':
            jsonResponse([
                'ok' => true,
                'action' => $action,
                'data' => astrologyApiRequest('current_vdasha', $birth),
            ]);
            break;

        case 'sub_vdasha':
            $lord = normalizeDashaLord((string)($input['maha_dasha_lord'] ?? ''));
            if ('' === $lord) {
                throw new InvalidArgumentException('maha_dasha_lord is required (e.g. jupiter).');
            }
            jsonResponse([
                'ok' => true,
                'action' => $action,
                'mahaDashaLord' => $lord,
                'data' => astrologyApiRequest('sub_vdasha/' . $lord, $birth),
            ]);
            break;

        case 'vimshottari':
            $bundle = fetchVimshottariBundle($birth);
            jsonResponse([
                'ok' => true,
                'action' => $action,
                ...$bundle,
            ]);
            break;

        case 'major_vdasha':
            jsonResponse([
                'ok' => true,
                'action' => $action,
                'data' => astrologyApiRequest('major_vdasha', $birth),
            ]);
            break;

        case 'birth_details':
            jsonResponse([
                'ok' => true,
                'action' => $action,
                'data' => astrologyApiRequest('birth_details', $birth),
            ]);
            break;

        default:
            jsonResponse(['ok' => false, 'error' => 'Unknown action: ' . $action], 400);
    }
} catch (InvalidArgumentException $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
}
