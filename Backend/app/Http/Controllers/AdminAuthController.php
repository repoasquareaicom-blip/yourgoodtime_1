<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:254'],
            'password' => ['required', 'string'],
        ]);
        $login = strtolower(trim($validated['email']));

        $admin = AdminUser::query()
            ->where('email', $login)
            ->first();

        if (! $admin || ! Hash::check($validated['password'], $admin->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid admin username or password.',
            ], 422);
        }

        if (! (bool) $admin->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This admin account is inactive.',
            ], 403);
        }

        $admin->tokens()->delete();
        $token = $admin->createToken('admin-auth-token')->plainTextToken;

        $admin->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'success' => true,
            'message' => 'Admin login successful.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->adminPayload($admin->refresh()),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'user' => $this->adminPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Admin logged out successfully.',
        ]);
    }

    private function adminPayload(AdminUser $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'is_admin' => true,
            'account_type' => 'admin',
            'last_login_at' => $admin->last_login_at?->toJSON(),
        ];
    }
}
