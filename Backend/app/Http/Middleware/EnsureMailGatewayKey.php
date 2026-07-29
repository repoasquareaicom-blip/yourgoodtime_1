<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMailGatewayKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredKey = (string) config('services.mail_gateway.key', '');
        $suppliedKey = (string) $request->header('X-Mail-Gateway-Key', '');

        if ($configuredKey === '' || $suppliedKey === '' || ! hash_equals($configuredKey, $suppliedKey)) {
            return $this->unauthorized();
        }

        return $next($request);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized request.',
        ], 401);
    }
}
