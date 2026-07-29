<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_requires_authentication(): void
    {
        $this->getJson('/api/auth/profile')
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_profile_returns_only_existing_safe_user_fields(): void
    {
        $user = User::factory()->create([
            'name' => 'Profile User',
            'email' => 'profile@example.com',
            'email_verified_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/auth/profile')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'profile' => [
                    'id' => $user->id,
                    'name' => 'Profile User',
                    'email' => 'profile@example.com',
                ],
            ])
            ->assertJsonStructure([
                'success',
                'profile' => [
                    'id',
                    'name',
                    'email',
                    'email_verified_at',
                    'created_at',
                ],
            ])
            ->assertJsonMissingPath('profile.mobile')
            ->assertJsonMissingPath('profile.gender')
            ->assertJsonMissingPath('profile.date_of_birth')
            ->assertJsonMissingPath('profile.profile_photo')
            ->assertJsonMissingPath('profile.password')
            ->assertJsonMissingPath('profile.remember_token')
            ->assertJsonMissingPath('profile.tokens')
            ->assertJsonMissingPath('profile.personal_access_tokens');
    }
}
