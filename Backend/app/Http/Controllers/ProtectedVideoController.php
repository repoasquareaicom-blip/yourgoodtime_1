<?php

namespace App\Http\Controllers;

use App\Services\ProtectedVideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProtectedVideoController extends Controller
{
    public function playbackUrl(Request $request, ProtectedVideoService $protectedVideoService): JsonResponse
    {
        $validated = $request->validate([
            'prediction_request_id' => ['required', 'integer'],
            'request_uuid' => ['required', 'uuid'],
            'prediction_type' => ['required', 'string', 'in:past,future'],
            'prediction_result_id' => ['nullable', 'integer'],
            'entitlement_id' => ['nullable', 'integer'],
            'video_id' => ['required', 'string', 'max:160'],
            'video_role' => ['nullable', 'string', 'in:IDENTITY,PREDICTION'],
            'language' => ['nullable', 'string', 'in:ta,en,hi'],
        ]);

        try {
            $playback = $protectedVideoService->createPlaybackPayload($request->user(), $validated);
        } catch (\RuntimeException $exception) {
            $payload = [
                'success' => false,
                'message' => $exception->getMessage(),
            ];

            if (method_exists($exception, 'debug')) {
                $payload['data'] = ['debug' => $exception->debug()];
            }

            return response()->json($payload, method_exists($exception, 'status') ? $exception->status() : 403);
        }

        return response()->json([
            'success' => true,
            'data' => array_merge($playback, [
                'expires_in' => max(60, (int) config('video.token_ttl_seconds', 600)),
            ]),
        ]);
    }

    public function stream(Request $request, ProtectedVideoService $protectedVideoService): JsonResponse|StreamedResponse
    {
        $token = (string) $request->query('token', '');

        if ($token === '') {
            return response()->json([
                'success' => false,
                'message' => 'Missing video token.',
            ], 401);
        }

        try {
            $payload = $protectedVideoService->payloadFromToken($token);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 401);
        }

        $path = $protectedVideoService->absolutePathForFilename((string) $payload['filename']);

        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return response()->json([
                'success' => false,
                'message' => 'Video file not found.',
            ], 404);
        }

        $size = filesize($path);

        if ($size === false || $size < 1) {
            return response()->json([
                'success' => false,
                'message' => 'Video file is empty.',
            ], 404);
        }

        $range = $this->parseRangeHeader((string) $request->headers->get('Range', ''), $size);

        if ($range === false) {
            return response()->json([
                'success' => false,
                'message' => 'Requested range is not satisfiable.',
            ], 416, [
                'Content-Range' => 'bytes */'.$size,
                'Accept-Ranges' => 'bytes',
            ]);
        }

        [$start, $end] = $range ?? [0, $size - 1];
        $length = $end - $start + 1;
        $status = $range === null ? 200 : 206;
        $headers = [
            'Content-Type' => 'video/mp4',
            'Accept-Ranges' => 'bytes',
            'Content-Length' => (string) $length,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        return response()->stream(function () use ($path, $start, $length): void {
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                return;
            }

            try {
                fseek($handle, $start);
                $remaining = $length;

                while ($remaining > 0 && ! feof($handle)) {
                    $chunkSize = min(8192, $remaining);
                    $buffer = fread($handle, $chunkSize);

                    if ($buffer === false || $buffer === '') {
                        break;
                    }

                    echo $buffer;
                    $remaining -= strlen($buffer);

                    if (connection_aborted()) {
                        break;
                    }
                }
            } finally {
                fclose($handle);
            }
        }, $status, $headers);
    }

    private function parseRangeHeader(string $rangeHeader, int $size): array|false|null
    {
        if ($rangeHeader === '') {
            return null;
        }

        if (! preg_match('/^bytes=(\d*)-(\d*)$/', trim($rangeHeader), $matches)) {
            return false;
        }

        $startText = $matches[1];
        $endText = $matches[2];

        if ($startText === '' && $endText === '') {
            return false;
        }

        if ($startText === '') {
            $suffixLength = (int) $endText;

            if ($suffixLength <= 0) {
                return false;
            }

            $start = max(0, $size - $suffixLength);
            $end = $size - 1;
        } else {
            $start = (int) $startText;
            $end = $endText === '' ? $size - 1 : (int) $endText;
        }

        if ($start > $end || $start >= $size) {
            return false;
        }

        return [$start, min($end, $size - 1)];
    }
}
