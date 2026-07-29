<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthMeTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_auth_me_rejects_invalid_token(): void
    {
        $this->withHeader('Authorization', 'Bearer invalid-token')
            ->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_auth_me_returns_safe_authenticated_user_payload(): void
    {
        $user = User::factory()->create([
            'name' => 'Current User',
            'email' => 'current@example.com',
            'email_verified_at' => now()->subDay(),
            'last_login_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => 'Current User',
                    'email' => 'current@example.com',
                ],
            ])
            ->assertJsonStructure([
                'success',
                'user' => [
                    'id',
                    'name',
                    'email',
                    'email_verified_at',
                    'last_login_at',
                    'created_at',
                ],
            ])
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.remember_token')
            ->assertJsonMissingPath('user.tokens')
            ->assertJsonMissingPath('user.personal_access_tokens');
    }
}
