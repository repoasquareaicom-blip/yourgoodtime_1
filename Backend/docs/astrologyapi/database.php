<?php

declare(strict_types=1);

/**
 * SQLite storage for cached AstrologyAPI reports.
 */

function getAstroDbPath(): string
{
    if (defined('ASTRO_DB_PATH') && '' !== ASTRO_DB_PATH) {
        return ASTRO_DB_PATH;
    }

    return __DIR__ . '/storage/astro_reports.sqlite';
}

function getAstroPdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = getAstroDbPath();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create database directory: ' . $dir);
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    initAstroSchema($pdo);

    return $pdo;
}

function initAstroSchema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS astro_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cache_key TEXT NOT NULL UNIQUE,
            datetime TEXT NOT NULL,
            timezone TEXT NOT NULL,
            latitude REAL NOT NULL,
            longitude REAL NOT NULL,
            place_name TEXT,
            chart_id TEXT NOT NULL,
            chart_style TEXT NOT NULL,
            report_json TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_astro_reports_created ON astro_reports(created_at DESC)');
}

function buildReportCacheKey(array $meta): string
{
    $payload = [
        'datetime' => $meta['datetime'],
        'timezone' => $meta['timezone'],
        'latitude' => round((float)$meta['latitude'], 4),
        'longitude' => round((float)$meta['longitude'], 4),
        'chart_id' => $meta['chart_id'],
        'chart_style' => $meta['chart_style'],
    ];

    return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
}

function findReportByCacheKey(string $cacheKey): ?array
{
    $stmt = getAstroPdo()->prepare('SELECT * FROM astro_reports WHERE cache_key = :cache_key LIMIT 1');
    $stmt->execute(['cache_key' => $cacheKey]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function findReportById(int $id): ?array
{
    $stmt = getAstroPdo()->prepare('SELECT * FROM astro_reports WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function deleteReportById(int $id): bool
{
    $stmt = getAstroPdo()->prepare('DELETE FROM astro_reports WHERE id = :id');
    $stmt->execute(['id' => $id]);

    return $stmt->rowCount() > 0;
}

function deleteAllReports(): int
{
    $count = (int)getAstroPdo()->query('SELECT COUNT(*) FROM astro_reports')->fetchColumn();
    getAstroPdo()->exec('DELETE FROM astro_reports');

    return $count;
}

function listRecentReports(int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    $stmt = getAstroPdo()->prepare(
        'SELECT id, datetime, timezone, latitude, longitude, place_name, chart_id, chart_style, created_at, updated_at
         FROM astro_reports ORDER BY updated_at DESC, id DESC LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function saveReport(array $meta, array $report): array
{
    $cacheKey = buildReportCacheKey($meta);
    $json = json_encode($report, JSON_THROW_ON_ERROR);
    $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    $pdo = getAstroPdo();
    $existing = findReportByCacheKey($cacheKey);

    if ($existing) {
        $stmt = $pdo->prepare(
            'UPDATE astro_reports
             SET place_name = :place_name, report_json = :report_json, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'place_name' => $meta['place_name'] ?? null,
            'report_json' => $json,
            'updated_at' => $now,
            'id' => (int)$existing['id'],
        ]);

        return findReportById((int)$existing['id']) ?? $existing;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO astro_reports
            (cache_key, datetime, timezone, latitude, longitude, place_name, chart_id, chart_style, report_json, created_at, updated_at)
         VALUES
            (:cache_key, :datetime, :timezone, :latitude, :longitude, :place_name, :chart_id, :chart_style, :report_json, :created_at, :updated_at)'
    );
    $stmt->execute([
        'cache_key' => $cacheKey,
        'datetime' => $meta['datetime'],
        'timezone' => $meta['timezone'],
        'latitude' => $meta['latitude'],
        'longitude' => $meta['longitude'],
        'place_name' => $meta['place_name'] ?? null,
        'chart_id' => $meta['chart_id'],
        'chart_style' => $meta['chart_style'],
        'report_json' => $json,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $id = (int)$pdo->lastInsertId();
    $saved = findReportById($id);
    if (!$saved) {
        throw new RuntimeException('Failed to read saved report from database.');
    }

    return $saved;
}

function decodeStoredReport(array $row): array
{
    $report = json_decode((string)$row['report_json'], true);
    if (!is_array($report)) {
        throw new RuntimeException('Stored report data is corrupted.');
    }

    return $report;
}

function formatReportRowSummary(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'datetime' => $row['datetime'],
        'timezone' => $row['timezone'],
        'latitude' => (float)$row['latitude'],
        'longitude' => (float)$row['longitude'],
        'place_name' => $row['place_name'],
        'chart_id' => $row['chart_id'],
        'chart_style' => $row['chart_style'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function buildReportResponse(array $row, array $report, bool $fromCache): array
{
    return [
        'ok' => true,
        'action' => 'report',
        'reportId' => (int)$row['id'],
        'fromCache' => $fromCache,
        'savedAt' => $row['updated_at'],
        'meta' => formatReportRowSummary($row),
        'report' => $report,
    ];
}
