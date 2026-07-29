<?php

namespace App\Helpers;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(string $message = '', mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public static function error(string $message = '', mixed $data = null, int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public static function validation(string $message = 'Validation error.', mixed $data = null): JsonResponse
    {
        return self::error($message, $data, 422);
    }

    public static function unauthorized(string $message = 'Unauthorized.', mixed $data = null): JsonResponse
    {
        return self::error($message, $data, 401);
    }

    public static function notFound(string $message = 'Not found.', mixed $data = null): JsonResponse
    {
        return self::error($message, $data, 404);
    }

    public static function serverError(string $message = 'Something went wrong.', mixed $data = null): JsonResponse
    {
        return self::error($message, $data, 500);
    }
}
