<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_password(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => ' Login@Example.com ',
            'password' => 'secret123',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
    }

    public function test_user_login_rejects_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'login@example.com',
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'message' => 'Invalid email or password.',
            ]);
    }

    public function test_user_login_guides_accounts_without_password_to_otp_setup(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.com',
        ]);
        DB::table('users')->where('id', $user->id)->update(['password' => '']);

        $this->postJson('/api/auth/login', [
            'email' => 'login@example.com',
            'password' => 'secret123',
        ])
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'code' => 'PASSWORD_NOT_SET',
                'next_action' => 'VERIFY_PASSWORD_SETUP_OTP',
                'account_state' => 'EXISTING_WITHOUT_PASSWORD',
            ]);
    }

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
