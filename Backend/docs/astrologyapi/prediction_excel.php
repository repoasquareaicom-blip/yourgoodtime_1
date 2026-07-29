<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

if (!function_exists('getPredictionDataDir')) {
    function getPredictionDataDir(): string
    {
        if (defined('PREDICTION_DATA_DIR') && '' !== PREDICTION_DATA_DIR) {
            return PREDICTION_DATA_DIR;
        }

        return dirname(__DIR__) . '/data';
    }
}

function getPredictionCacheDir(): string
{
    return getPredictionDataDir() . '/cache';
}

function getPredictionCachePath(string $filename, string $dashaLord): string
{
    $base = (string)preg_replace('/\.xlsx?$/i', '', basename($filename));
    $lord = normalizeDashaLordKey($dashaLord);

    return getPredictionCacheDir() . DIRECTORY_SEPARATOR . $base . '__' . $lord . '.json';
}

/**
 * @return array<int, array<int, string>>|null|false false when no cache file exists
 */
function loadPredictionGridFromCache(string $filename, string $dashaLord): array|null|false
{
    $path = getPredictionCachePath($filename, $dashaLord);
    if (!is_file($path)) {
        return false;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || '' === $raw) {
        return false;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return false;
    }

    if (array_key_exists('found', $decoded) && !$decoded['found']) {
        return null;
    }

    $grid = $decoded['grid'] ?? null;

    return is_array($grid) ? $grid : null;
}

/**
 * @param list<list<string>>|null $grid
 */
function savePredictionGridToCache(string $filename, string $dashaLord, ?array $grid): void
{
    $dir = getPredictionCacheDir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }

    $payload = [
        'found' => null !== $grid,
        'grid' => $grid ?? [],
        'cachedAt' => date('c'),
    ];

    file_put_contents(
        getPredictionCachePath($filename, $dashaLord),
        json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
    );
}

function isShellExecAvailable(): bool
{
    if (!function_exists('shell_exec')) {
        return false;
    }

    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));

    return !in_array('shell_exec', $disabled, true);
}

function getPredictionExcelReaderError(string $path): ?string
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ('xlsx' === $extension && !class_exists('ZipArchive')) {
        if (!isShellExecAvailable()) {
            return 'PHP zip extension is disabled and shell_exec is unavailable. '
                . 'Upload pre-built JSON cache files to data/cache/ (run build_prediction_cache.php locally), '
                . 'or ask your host to enable the PHP zip extension.';
        }
    }

    if (!class_exists(IOFactory::class) && !isShellExecAvailable()) {
        return 'Excel reader is not available on this server. Upload data/cache/ JSON files built locally.';
    }

    return null;
}

const PREDICTION_ZODIAC_SIGNS = [
    'aries',
    'taurus',
    'gemini',
    'cancer',
    'leo',
    'virgo',
    'libra',
    'scorpio',
    'sagittarius',
    'capricorn',
    'aquarius',
    'pisces',
];

function normalizePredictionSign(string $value): string
{
    $key = strtolower(trim($value));
    $key = preg_replace('/[^a-z]/', '', $key) ?? '';

    static $aliases = [
        'mesham' => 'aries',
        'mesh' => 'aries',
        'mesha' => 'aries',
        'rishabam' => 'taurus',
        'rishaba' => 'taurus',
        'vrishabha' => 'taurus',
        'vrishabam' => 'taurus',
        'mithunam' => 'gemini',
        'mithuna' => 'gemini',
        'kadagam' => 'cancer',
        'kadaga' => 'cancer',
        'kataka' => 'cancer',
        'simmam' => 'leo',
        'simha' => 'leo',
        'kanni' => 'virgo',
        'kanya' => 'virgo',
        'thulam' => 'libra',
        'thula' => 'libra',
        'tula' => 'libra',
        'viruchigam' => 'scorpio',
        'vrischika' => 'scorpio',
        'virishika' => 'scorpio',
        'viruchika' => 'scorpio',
        'dhanusu' => 'sagittarius',
        'dhanu' => 'sagittarius',
        'dhanus' => 'sagittarius',
        'magaram' => 'capricorn',
        'makara' => 'capricorn',
        'kumbam' => 'aquarius',
        'kumbha' => 'aquarius',
        'meenam' => 'pisces',
        'meena' => 'pisces',
    ];

    if (isset($aliases[$key])) {
        return $aliases[$key];
    }

    return $key;
}

function normalizePredictionCell(mixed $value): string
{
    if (null === $value) {
        return '';
    }

    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }

    return trim((string)$value);
}

function parsePredictionPosition(string $value): ?int
{
    $text = strtolower(trim($value));
    if ('' === $text) {
        return null;
    }

    if (preg_match('/(?:position|house|pos|நிலை)?\s*(\d{1,2})\b/i', $text, $matches)) {
        $position = (int)$matches[1];
        if ($position >= 1 && $position <= 12) {
            return $position;
        }
    }

    if (preg_match('/^\d{1,2}$/', $text)) {
        $position = (int)$text;
        if ($position >= 1 && $position <= 12) {
            return $position;
        }
    }

    return null;
}

function isPlausiblePredictionCalendarYear(int $year): bool
{
    return $year >= 1900 && $year <= 2100;
}

/**
 * @return array{start:int,end:int}|null
 */
function parsePredictionYearCell(string $value): ?array
{
    $text = trim($value);
    if ('' === $text) {
        return null;
    }

    if (preg_match('/^(\d{4})\s*(?:-|–|—|to|\.\.|\/)\s*(\d{4})$/i', $text, $matches)) {
        $start = (int)$matches[1];
        $end = (int)$matches[2];
        if (!isPlausiblePredictionCalendarYear($start) || !isPlausiblePredictionCalendarYear($end)) {
            return null;
        }

        return ['start' => min($start, $end), 'end' => max($start, $end)];
    }

    if (preg_match('/^\d{4}$/', $text)) {
        $year = (int)$text;
        if (!isPlausiblePredictionCalendarYear($year)) {
            return null;
        }

        return ['start' => $year, 'end' => $year];
    }

    return null;
}

function isPredictionYearHeaderCell(string $value): bool
{
    return null !== parsePredictionYearCell($value);
}

function isPredictionLaknamCell(string $value): bool
{
    $sign = normalizePredictionSign($value);

    return in_array($sign, PREDICTION_ZODIAC_SIGNS, true);
}

function isPredictionPositionLabelCell(string $value): bool
{
    $text = strtolower(trim($value));

    return str_contains($text, 'position')
        || str_contains($text, 'house')
        || str_contains($text, 'pos')
        || str_contains($text, 'நிலை');
}

function isPredictionYearLabelCell(string $value): bool
{
    $text = strtolower(trim($value));

    return str_contains($text, 'year')
        || str_contains($text, 'duration')
        || str_contains($text, 'வருட');
}

function isPredictionCodeLabelCell(string $value): bool
{
    $text = strtolower(trim($value));

    return str_contains($text, 'code')
        || str_contains($text, 'prediction')
        || str_contains($text, 'குறி');
}

function predictionYearRangeMatches(
    array $columnRange,
    int $targetYear,
    int $dashaStartYear,
    int $dashaEndYear
): bool {
    if ($targetYear < $columnRange['start'] || $targetYear > $columnRange['end']) {
        return false;
    }

    return $columnRange['start'] <= $dashaEndYear && $columnRange['end'] >= $dashaStartYear;
}

function mapCalendarYearToDummyYear(array $pairs, int $targetYear, int $dashaStartYear): ?int
{
    if ([] === $pairs) {
        return null;
    }

    $firstRange = parsePredictionYearCell((string)($pairs[0]['year'] ?? ''));
    if (null === $firstRange) {
        return null;
    }

    return $firstRange['start'] + ($targetYear - $dashaStartYear);
}

function dummyYearMatchesPair(int $dummyYear, array $pair): bool
{
    $range = parsePredictionYearCell((string)($pair['year'] ?? ''));
    if (null === $range) {
        return false;
    }

    return $dummyYear >= $range['start'] && $dummyYear <= $range['end'];
}

/**
 * @return list<array{year:string,code:string}>
 */
function extractPredictionYearCodePairs(array $row): array
{
    $pairs = [];
    $colCount = count($row);

    for ($col = 2; $col < $colCount; $col++) {
        $yearCell = normalizePredictionCell($row[$col] ?? '');
        if ('' === $yearCell || null === parsePredictionYearCell($yearCell)) {
            continue;
        }

        $codeCell = '';
        if ($col + 1 < $colCount) {
            $codeCell = normalizePredictionCell($row[$col + 1] ?? '');
        }

        $pairs[] = [
            'year' => $yearCell,
            'code' => $codeCell,
        ];
        $col++;
    }

    return $pairs;
}

/**
 * @return array<int, string>|null
 */
function findPredictionDataRow(array $rows, string $laknam, int $position): ?array
{
    $target = normalizePredictionSign($laknam);
    if ('' === $target) {
        return null;
    }

    foreach ($rows as $row) {
        if (!is_array($row) || count($row) < 2) {
            continue;
        }

        $rowLaknam = normalizePredictionCell($row[0] ?? '');
        if ('' === $rowLaknam || !isPredictionLaknamCell($rowLaknam)) {
            continue;
        }

        if (normalizePredictionSign($rowLaknam) !== $target) {
            continue;
        }

        $rowPosition = parsePredictionPosition(normalizePredictionCell($row[1] ?? ''));
        if ($rowPosition !== $position) {
            continue;
        }

        return $row;
    }

    return null;
}

function pickPredictionCodeFromPairs(
    array $pairs,
    int $targetYear,
    int $dashaStartYear,
    int $dashaEndYear
): string {
    if ($targetYear < $dashaStartYear || $targetYear > $dashaEndYear) {
        return '';
    }

    $dummyYear = mapCalendarYearToDummyYear($pairs, $targetYear, $dashaStartYear);
    if (null === $dummyYear) {
        return '';
    }

    foreach ($pairs as $pair) {
        if (!dummyYearMatchesPair($dummyYear, $pair)) {
            continue;
        }

        $code = normalizePredictionCell($pair['code'] ?? '');
        if ('' !== $code && !isPredictionYearHeaderCell($code)) {
            return $code;
        }
    }

    return '';
}

function normalizeDashaLordKey(string $lord): string
{
    return strtoupper(preg_replace('/[^a-z]/i', '', $lord) ?? '');
}

function resolvePredictionSheetFromSpreadsheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, string $dashaLord): ?Worksheet
{
    $key = normalizeDashaLordKey($dashaLord);
    if ('' === $key) {
        return null;
    }

    foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
        $title = normalizeDashaLordKey($sheet->getTitle());
        if ($title === $key || str_starts_with($title, $key)) {
            return $sheet;
        }
    }

    foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
        $header = strtoupper(normalizePredictionCell($sheet->getCell([1, 1])->getCalculatedValue()));
        if ('' !== $header && str_contains($header, $key) && str_contains($header, 'DASA')) {
            return $sheet;
        }
    }

    return null;
}

function canUsePhpSpreadsheetForExcel(string $path): bool
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ('xlsx' === $extension && !class_exists('ZipArchive')) {
        return false;
    }

    return class_exists(IOFactory::class);
}

/**
 * @return list<list<string>>
 */
function loadPredictionExcelGridViaPython(string $path, string $dashaLord): ?array
{
    $script = __DIR__ . '/read_excel_grid.py';
    if (!is_file($script)) {
        throw new RuntimeException('Excel reader script is missing.');
    }

    $python = getenv('PYTHON_PATH') ?: 'python';
    $command = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' '
        . escapeshellarg($path) . ' ' . escapeshellarg($dashaLord);
    $output = shell_exec($command . ' 2>&1');
    if (!is_string($output) || '' === trim($output)) {
        throw new RuntimeException('Could not read Excel file via Python.');
    }

    $decoded = json_decode(trim($output), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid Excel JSON from Python reader.');
    }

    if (array_key_exists('found', $decoded) && !$decoded['found']) {
        return null;
    }

    $rows = $decoded['grid'] ?? $decoded;
    if (!is_array($rows)) {
        throw new RuntimeException('Invalid Excel grid from Python reader.');
    }

    $grid = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $grid[] = array_map(
            static fn ($cell): string => normalizePredictionCell($cell),
            $row
        );
    }

    return $grid;
}

function resolvePredictionExcelPath(string $filename): string
{
    $filename = basename($filename);
    if (!preg_match('/\.xlsx?$/i', $filename)) {
        throw new InvalidArgumentException('Prediction file must be .xls or .xlsx.');
    }

    $path = getPredictionDataDir() . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        throw new RuntimeException('Prediction file not found: ' . $filename);
    }

    return $path;
}

/**
 * @return list<list<string>>|null Null when the mahadasha lord sheet is missing.
 */
function loadPredictionExcelGrid(string $filename, string $dashaLord, bool $skipCache = false): ?array
{
    static $cache = [];

    $cacheKey = $filename . '|' . normalizeDashaLordKey($dashaLord);
    if (!$skipCache && array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (!$skipCache) {
        $cached = loadPredictionGridFromCache($filename, $dashaLord);
        if (false !== $cached) {
            $cache[$cacheKey] = $cached;

            return $cached;
        }
    }

    $path = resolvePredictionExcelPath($filename);
    $readerError = getPredictionExcelReaderError($path);
    if (null !== $readerError) {
        throw new RuntimeException(
            $readerError . ' Upload pre-built JSON files from data/cache/ to production (run build_prediction_cache.php locally).'
        );
    }

    if (canUsePhpSpreadsheetForExcel($path)) {
        $spreadsheet = IOFactory::load($path);
        $sheet = resolvePredictionSheetFromSpreadsheet($spreadsheet, $dashaLord);
        if (null === $sheet) {
            $cache[$cacheKey] = null;
            savePredictionGridToCache($filename, $dashaLord, null);

            return null;
        }
        $grid = readPredictionWorksheetGrid($sheet);
        unset($spreadsheet);
    } else {
        $grid = loadPredictionExcelGridViaPython($path, $dashaLord);
    }

    $cache[$cacheKey] = $grid;
    savePredictionGridToCache($filename, $dashaLord, $grid);

    return $grid;
}

/**
 * @return list<list<string>>
 */
function readPredictionWorksheetGrid(Worksheet $sheet): array
{
    $rows = [];
    $maxRow = min((int)$sheet->getHighestDataRow(), 320);
    $maxColIndex = min(
        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn()),
        48
    );

    for ($row = 1; $row <= $maxRow; $row++) {
        $line = [];
        $hasValue = false;
        for ($col = 1; $col <= $maxColIndex; $col++) {
            $value = normalizePredictionCell($sheet->getCell([$col, $row])->getCalculatedValue());
            $line[] = $value;
            if ('' !== $value) {
                $hasValue = true;
            }
        }
        while ($line && '' === $line[array_key_last($line)]) {
            array_pop($line);
        }
        if ($hasValue) {
            $rows[] = $line;
        }
    }

    return $rows;
}

function lookupPredictionCodeFromExcel(
    string $filename,
    string $dashaLord,
    string $laknam,
    int $position,
    int $targetYear,
    int $dashaStartYear,
    int $dashaEndYear
): string {
    if ('' === normalizeDashaLordKey($dashaLord)) {
        return '';
    }

    if ($position < 1 || $position > 12) {
        throw new InvalidArgumentException('position must be between 1 and 12.');
    }

    if ($targetYear < 1900 || $targetYear > 2200) {
        throw new InvalidArgumentException('targetYear is out of range.');
    }

    $grid = loadPredictionExcelGrid($filename, $dashaLord);
    if (null === $grid) {
        return '';
    }

    $row = findPredictionDataRow($grid, $laknam, $position);
    if (null === $row) {
        return '';
    }

    $pairs = extractPredictionYearCodePairs($row);
    if ([] === $pairs) {
        return '';
    }

    return pickPredictionCodeFromPairs($pairs, $targetYear, $dashaStartYear, $dashaEndYear);
}

/**
 * @param list<array<string, mixed>> $items
 * @return list<array<string, mixed>>
 */
function lookupPredictionCodesBatch(array $items): array
{
    $results = [];

    foreach ($items as $index => $item) {
        $filename = basename((string)($item['filename'] ?? ''));
        $dashaLord = trim((string)($item['dashaLord'] ?? ''));
        $laknam = trim((string)($item['laknam'] ?? ''));
        $position = (int)($item['position'] ?? 0);
        $targetYear = (int)($item['year'] ?? 0);
        $dashaStartYear = (int)($item['dashaStartYear'] ?? 0);
        $dashaEndYear = (int)($item['dashaEndYear'] ?? 0);

        try {
            if ('' === $filename || '' === $dashaLord || '' === $laknam || $position < 1 || $targetYear < 1) {
                throw new InvalidArgumentException('filename, dashaLord, laknam, position and year are required.');
            }

            if ($dashaStartYear < 1 || $dashaEndYear < 1) {
                throw new InvalidArgumentException('dashaStartYear and dashaEndYear are required.');
            }

            if ($dashaStartYear > $dashaEndYear) {
                [$dashaStartYear, $dashaEndYear] = [$dashaEndYear, $dashaStartYear];
            }

            $code = lookupPredictionCodeFromExcel(
                $filename,
                $dashaLord,
                $laknam,
                $position,
                $targetYear,
                $dashaStartYear,
                $dashaEndYear
            );

            $results[] = [
                'index' => (int)($item['index'] ?? $index),
                'filename' => $filename,
                'dashaLord' => $dashaLord,
                'year' => $targetYear,
                'code' => $code,
                'ok' => true,
            ];
        } catch (Throwable $e) {
            $results[] = [
                'index' => (int)($item['index'] ?? $index),
                'filename' => $filename,
                'year' => $targetYear,
                'code' => '',
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    return $results;
}

function normalizeNakshatraLagnamKey(string $value): string
{
    $key = strtolower(trim($value));
    $key = preg_replace('/[^a-z]/', '', $key) ?? '';

    static $aliases = [
        'ashwini' => 'aswini',
        'aswini' => 'aswini',
        'bharani' => 'bharani',
        'krithika' => 'krittika',
        'krittika' => 'krittika',
        'rohini' => 'rohini',
        'mrigashira' => 'mrigasira',
        'mrigshira' => 'mrigasira',
        'mrigasira' => 'mrigasira',
        'ardra' => 'ardra',
        'punarvasu' => 'punarvasu',
        'pushya' => 'pushya',
        'ashlesha' => 'ashlesha',
        'magha' => 'makha',
        'makha' => 'makha',
        'pubba' => 'poorvaphalguni',
        'purvaphalguni' => 'poorvaphalguni',
        'poorvaphalguni' => 'poorvaphalguni',
        'uttaraphalguni' => 'uttaraphalguni',
        'hasta' => 'hastha',
        'hastha' => 'hastha',
        'chitra' => 'chitra',
        'swati' => 'swathi',
        'swathi' => 'swathi',
        'vishakha' => 'vishakha',
        'anuradha' => 'anuradha',
        'jyestha' => 'jyestha',
        'jestha' => 'jyestha',
        'mula' => 'moola',
        'moola' => 'moola',
        'purvashada' => 'poorvashada',
        'poorvashada' => 'poorvashada',
        'uttarashada' => 'uttarashada',
        'shravana' => 'sravana',
        'sravana' => 'sravana',
        'dhanishta' => 'dhanistha',
        'dhanistha' => 'dhanistha',
        'shatabhisha' => 'satabhisha',
        'satabhisha' => 'satabhisha',
        'purvabhadrapada' => 'poorvabhadrapada',
        'poorvabhadrapada' => 'poorvabhadrapada',
        'uttarabhadrapada' => 'uttarabhadrapada',
        'revati' => 'revathi',
        'revathi' => 'revathi',
    ];

    if (isset($aliases[$key])) {
        return $aliases[$key];
    }

    return $key;
}

function getNakshatraLagnamExcelPath(): string
{
    return getPredictionDataDir() . DIRECTORY_SEPARATOR . 'nakshatra lagnam.xlsx';
}

function getNakshatraLagnamCachePath(): string
{
    return getPredictionCacheDir() . DIRECTORY_SEPARATOR . 'nakshatra_lagnam.json';
}

/**
 * @return array<string, string>|false false when no cache file exists
 */
function loadNakshatraLagnamIndexFromCache(): array|false
{
    $path = getNakshatraLagnamCachePath();
    if (!is_file($path)) {
        return false;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || '' === $raw) {
        return false;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return false;
    }

    $index = $decoded['index'] ?? null;

    return is_array($index) ? $index : false;
}

/**
 * @return list<list<string>>|false
 */
function loadNakshatraLagnamGridFromCache(): array|false
{
    $path = getNakshatraLagnamCachePath();
    if (!is_file($path)) {
        return false;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || '' === $raw) {
        return false;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return false;
    }

    $grid = $decoded['grid'] ?? null;

    return is_array($grid) ? $grid : false;
}

/**
 * @param array<string, string> $index
 * @param list<list<string>>|null $grid
 */
function saveNakshatraLagnamCache(array $index, ?array $grid = null): void
{
    $dir = getPredictionCacheDir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }

    $payload = [
        'found' => [] !== $index,
        'index' => $index,
        'grid' => $grid ?? [],
        'source' => basename(getNakshatraLagnamExcelPath()),
        'builtAt' => date('c'),
    ];

    file_put_contents(
        getNakshatraLagnamCachePath(),
        json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
    );
}

/**
 * Build lookup index from nakshatra lagnam rows. Requires local Excel or existing grid.
 *
 * @return array{index: array<string, string>, grid: list<list<string>>}
 */
function buildNakshatraLagnamCacheData(): array
{
    $grid = loadNakshatraLagnamGrid(true);
    $index = [];

    foreach ($grid as $row) {
        if (!is_array($row) || count($row) < 5) {
            continue;
        }

        $lagnam = normalizePredictionSign(normalizePredictionCell($row[1] ?? ''));
        $rasi = normalizePredictionSign(normalizePredictionCell($row[2] ?? ''));
        $nakshatra = normalizeNakshatraLagnamKey(normalizePredictionCell($row[3] ?? ''));
        $videoId = normalizePredictionCell($row[4] ?? '');

        if ('' === $lagnam || '' === $rasi || '' === $nakshatra || '' === $videoId) {
            continue;
        }

        $index[$lagnam . '|' . $rasi . '|' . $nakshatra] = $videoId;
    }

    return [
        'index' => $index,
        'grid' => $grid,
    ];
}

function nakshatraLagnamLookupKey(string $lagnam, string $rasi, string $nakshatra): string
{
    return normalizePredictionSign($lagnam) . '|'
        . normalizePredictionSign($rasi) . '|'
        . normalizeNakshatraLagnamKey($nakshatra);
}

/**
 * @return list<list<string>>
 */
function loadNakshatraLagnamGrid(bool $forceExcel = false): array
{
    static $cache = null;
    if (!$forceExcel && null !== $cache) {
        return $cache;
    }

    if (!$forceExcel) {
        $fromCache = loadNakshatraLagnamGridFromCache();
        if (false !== $fromCache) {
            $cache = $fromCache;

            return $cache;
        }
    }

    $path = getNakshatraLagnamExcelPath();
    if (!is_file($path)) {
        if (!$forceExcel) {
            $cache = [];
        }

        return [];
    }

    $readerError = getPredictionExcelReaderError($path);
    if (null !== $readerError) {
        throw new RuntimeException($readerError);
    }

    if (canUsePhpSpreadsheetForExcel($path)) {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheet(0);
        $grid = readPredictionWorksheetGrid($sheet);
        if (!$forceExcel) {
            $cache = $grid;
        }

        return $grid;
    }

    $script = __DIR__ . '/read_nakshatra_lagnam_grid.py';
    if (!is_file($script)) {
        throw new RuntimeException('Nakshatra lagnam Excel reader script is missing.');
    }

    $python = getenv('PYTHON_PATH') ?: 'python';
    $command = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($path);
    $output = shell_exec($command . ' 2>&1');
    if (!is_string($output) || '' === trim($output)) {
        throw new RuntimeException('Could not read nakshatra lagnam Excel file.');
    }

    $decoded = json_decode(trim($output), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid nakshatra lagnam Excel JSON.');
    }

    if (array_key_exists('found', $decoded) && !$decoded['found']) {
        if (!$forceExcel) {
            $cache = [];
        }

        return [];
    }

    $rows = $decoded['grid'] ?? $decoded;
    if (!is_array($rows)) {
        throw new RuntimeException('Invalid nakshatra lagnam Excel grid.');
    }

    $grid = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $grid[] = array_map(
            static fn ($cell): string => normalizePredictionCell($cell),
            $row
        );
    }

    if (!$forceExcel) {
        $cache = $grid;
    }

    return $grid;
}

/**
 * @return array{
 *   found: bool,
 *   videoId: string,
 *   code: string,
 *   lagnam?: string,
 *   rasi?: string,
 *   nakshatra?: string
 * }
 */
function lookupNakshatraLagnamVideoCode(string $lagnam, string $rasi, string $nakshatra): array
{
    $targetLagnam = normalizePredictionSign($lagnam);
    $targetRasi = normalizePredictionSign($rasi);
    $targetNakshatra = normalizeNakshatraLagnamKey($nakshatra);
    $lookupKey = nakshatraLagnamLookupKey($lagnam, $rasi, $nakshatra);

    if ('' === $targetLagnam || '' === $targetRasi || '' === $targetNakshatra) {
        return [
            'found' => false,
            'videoId' => '',
            'code' => '',
            'error' => 'Missing lagnam, rasi or nakshatra.',
        ];
    }

    $index = loadNakshatraLagnamIndexFromCache();
    if (false !== $index) {
        $videoId = normalizePredictionCell($index[$lookupKey] ?? '');
        if ('' !== $videoId) {
            return [
                'found' => true,
                'videoId' => $videoId,
                'code' => $videoId,
                'lagnam' => $lagnam,
                'rasi' => $rasi,
                'nakshatra' => $nakshatra,
                'source' => 'cache',
            ];
        }

        return [
            'found' => false,
            'videoId' => '',
            'code' => '',
            'lagnam' => $lagnam,
            'rasi' => $rasi,
            'nakshatra' => $nakshatra,
            'error' => 'No matching row in nakshatra lagnam cache.',
        ];
    }

    try {
        foreach (loadNakshatraLagnamGrid() as $row) {
            if (!is_array($row) || count($row) < 5) {
                continue;
            }

            $rowLagnam = normalizePredictionSign(normalizePredictionCell($row[1] ?? ''));
            $rowRasi = normalizePredictionSign(normalizePredictionCell($row[2] ?? ''));
            $rowNakshatra = normalizeNakshatraLagnamKey(normalizePredictionCell($row[3] ?? ''));
            if ('' === $rowLagnam || '' === $rowRasi || '' === $rowNakshatra) {
                continue;
            }

            if ($rowLagnam !== $targetLagnam || $rowRasi !== $targetRasi || $rowNakshatra !== $targetNakshatra) {
                continue;
            }

            $videoId = normalizePredictionCell($row[4] ?? '');

            return [
                'found' => true,
                'videoId' => $videoId,
                'code' => $videoId,
                'lagnam' => $lagnam,
                'rasi' => $rasi,
                'nakshatra' => $nakshatra,
                'source' => 'excel',
            ];
        }
    } catch (Throwable $e) {
        return [
            'found' => false,
            'videoId' => '',
            'code' => '',
            'lagnam' => $lagnam,
            'rasi' => $rasi,
            'nakshatra' => $nakshatra,
            'error' => $e->getMessage(),
        ];
    }

    return [
        'found' => false,
        'videoId' => '',
        'code' => '',
        'lagnam' => $lagnam,
        'rasi' => $rasi,
        'nakshatra' => $nakshatra,
        'error' => is_file(getNakshatraLagnamCachePath())
            ? 'No matching row in nakshatra lagnam data.'
            : 'Intro code cache missing. Run build_prediction_cache.php locally and upload data/cache/nakshatra_lagnam.json.',
    ];
}
