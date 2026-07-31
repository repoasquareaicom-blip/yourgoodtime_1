<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
                    'country_code',
                    'phone_e164',
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

    public function test_profile_can_update_editable_fields_and_password_without_email_change(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'profile@example.com',
            'password' => Hash::make('oldsecret'),
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/auth/profile', [
            'name' => 'New Name',
            'email' => 'changed@example.com',
            'country_code' => 'us',
            'phone_e164' => '+14155552671',
            'password' => 'newsecret123',
            'password_confirmation' => 'newsecret123',
        ])
            ->assertOk()
            ->assertJsonPath('profile.name', 'New Name')
            ->assertJsonPath('profile.email', 'profile@example.com')
            ->assertJsonPath('profile.country_code', 'US')
            ->assertJsonPath('profile.phone_e164', '+14155552671');

        $user->refresh();
        $this->assertSame('profile@example.com', $user->email);
        $this->assertTrue(Hash::check('newsecret123', $user->password));
    }
}
