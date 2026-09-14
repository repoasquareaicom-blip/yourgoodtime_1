<?php

use App\Models\PredictionRequest;
use App\Models\PredictionResult;
use App\Services\Prediction\PredictionEngineService;
use App\Services\Prediction\PredictionResponseBuilder;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$requestId = null;
$type = 'past';
$force = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--request=')) {
        $requestId = (int) substr($arg, strlen('--request='));
    } elseif (str_starts_with($arg, '--type=')) {
        $type = strtolower(substr($arg, strlen('--type=')));
    } elseif ($arg === '--force') {
        $force = true;
    }
}

if (! $requestId || ! in_array($type, ['past'], true)) {
    fwrite(STDERR, "Usage: php scripts/regenerate-prediction-result.php --request=35 --type=past [--force]\n");
    fwrite(STDERR, "Only past prediction regeneration is supported by this script.\n");
    exit(1);
}

$request = PredictionRequest::query()->findOrFail($requestId);
$existingResult = PredictionResult::query()
    ->where('prediction_request_id', $request->id)
    ->where('prediction_type', $type)
    ->latest('id')
    ->first();

$timeline = app(PredictionEngineService::class)->preparePredictionTimeline($request->id, $type);
$result = app(PredictionResponseBuilder::class)->buildTimeline($timeline);
$warnings = collect($result['prediction_videos'] ?? [])
    ->pluck('warning')
    ->filter()
    ->values()
    ->all();

$payload = [
    'prediction_request_id' => $result['prediction_request_id'] ?? $request->id,
    'type' => $result['prediction_type'] ?? $type,
    'identity_video' => $result['identity_video'] ?? null,
    'prediction_videos' => $result['prediction_videos'] ?? [],
    'summary' => $result['summary'] ?? [],
    'warnings' => $warnings,
];

$rangeLabel = static function (array $video, string $prefix, string $singleKey): mixed {
    $from = $video[$prefix.'_from'] ?? null;
    $to = $video[$prefix.'_to'] ?? null;

    if ($from !== null && $to !== null && (string) $from !== (string) $to) {
        return $from.'-'.$to;
    }

    return $video[$singleKey] ?? $from ?? null;
};

$previewRows = collect($payload['prediction_videos'])
    ->filter(fn (array $video): bool => ($video['major_dasha'] ?? null) === 'Mercury')
    ->take(3)
    ->map(fn (array $video): array => [
        'age' => $rangeLabel($video, 'age', 'age'),
        'system_year' => $rangeLabel($video, 'system_year', 'system_year'),
        'matched_year' => $video['matched_year'] ?? null,
        'workbook' => $video['workbook'] ?? null,
        'sheet' => $video['sheet'] ?? null,
        'cell' => $video['excel_cell'] ?? null,
        'video_ids' => $video['video_ids'] ?? [],
    ])
    ->values()
    ->all();

echo "Request #{$request->id} {$type} regeneration ".($force ? 'FORCE' : 'DRY RUN').PHP_EOL;
echo 'Existing result: '.($existingResult ? "#{$existingResult->id}, generated_at={$existingResult->generated_at}" : 'none').PHP_EOL;
echo "Preview rows:".PHP_EOL;
print_r($previewRows);

if (! $force) {
    echo "No DB changes made. Re-run with --force to save this payload.".PHP_EOL;
    exit(0);
}

$saved = PredictionResult::query()->updateOrCreate(
    [
        'prediction_request_id' => $request->id,
        'prediction_type' => $type,
    ],
    [
        'user_id' => $existingResult?->user_id ?? $request->user_id,
        'prediction_entitlement_id' => null,
        'identity_video_id' => data_get($payload, 'identity_video.video_id'),
        'result_payload' => $payload,
        'summary_payload' => $payload['summary'] ?? [],
        'warnings_payload' => $payload['warnings'] ?? [],
        'status' => 'completed',
        'generated_at' => now(),
    ]
);

echo "Saved result #{$saved->id}, generated_at={$saved->generated_at}".PHP_EOL;
