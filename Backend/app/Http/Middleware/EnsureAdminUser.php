<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof AdminUser || ! (bool) $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Admin access is required.',
            ], 403);
        }

        return $next($request);
    }
}
