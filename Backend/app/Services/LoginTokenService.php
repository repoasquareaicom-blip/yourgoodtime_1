<?php

namespace App\Services;

use App\Models\User;

class LoginTokenService extends BaseService
{
    public function findUserByEmail(string $email): ?User
    {
        return User::query()
            ->where('email', strtolower(trim($email)))
            ->first();
    }

    public function isActive(User $user): bool
    {
        return (bool) $user->is_active;
    }

    public function createSingleDeviceToken(User $user): string
    {
        $user->tokens()->delete();

        $token = $user->createToken('auth-token')->plainTextToken;

        $user->forceFill([
            'last_login_at' => now(),
        ])->save();

        return $token;
    }

    public function safeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone_e164' => $user->phone_e164,
        ];
    }
}
