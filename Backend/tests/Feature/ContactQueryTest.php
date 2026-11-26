<?php

namespace Tests\Feature;

use App\Mail\ContactQuerySupportMail;
use App\Mail\ContactQueryThanksMail;
use App\Models\AdminUser;
use App\Models\ContactQuery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContactQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_submit_a_query_and_emails_are_sent(): void
    {
        Mail::fake();
        Storage::fake('local');

        $this->post('/api/contact-queries', [
            'email' => ' Client@Example.com ',
            'phone' => '',
            'message' => 'Please help me understand my past prediction.',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $query = ContactQuery::query()->firstOrFail();

        $this->assertSame('client@example.com', $query->email);
        $this->assertNull($query->phone);
        $this->assertNull($query->user_id);
        $this->assertSame('NEW', $query->status);

        Mail::assertSent(ContactQueryThanksMail::class, fn (ContactQueryThanksMail $mail): bool => $mail->hasTo('client@example.com'));
        Mail::assertSent(ContactQuerySupportMail::class, fn (ContactQuerySupportMail $mail): bool => $mail->hasTo('support@yourgoodtime.in'));
        Http::assertNothingSent();
    }

    public function test_registered_email_is_linked_and_attachment_is_stored(): void
    {
        Mail::fake();
        Storage::fake('local');

        $user = User::factory()->create([
            'email' => 'member@example.com',
        ]);

        $file = UploadedFile::fake()->create('notes.pdf', 400, 'application/pdf');

        $this->post('/api/contact-queries', [
            'email' => 'member@example.com',
            'phone' => '7200222233',
            'message' => 'I need help with my future reading payment.',
            'attachment' => $file,
        ])
            ->assertCreated();

        $query = ContactQuery::query()->firstOrFail();

        $this->assertSame($user->id, $query->user_id);
        $this->assertSame('7200222233', $query->phone);
        $this->assertTrue($query->hasAttachment());
        Storage::disk('local')->assertExists($query->attachment_path);

        Mail::assertSent(ContactQuerySupportMail::class, 1);
        Mail::assertSent(ContactQueryThanksMail::class, 1);
    }

    public function test_attachment_larger_than_two_megabytes_is_rejected(): void
    {
        Mail::fake();

        $file = UploadedFile::fake()->create('large.pdf', 2049, 'application/pdf');

        $this->withHeader('Accept', 'application/json')->post('/api/contact-queries', [
            'email' => 'client@example.com',
            'message' => 'Please look at this large file.',
            'attachment' => $file,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachment');

        $this->assertSame(0, ContactQuery::query()->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_admin_can_list_and_review_queries(): void
    {
        Mail::fake();
        Storage::fake('local');

        $query = ContactQuery::query()->create([
            'email' => 'client@example.com',
            'message' => 'Need guidance on temple remedy.',
            'status' => 'NEW',
        ]);

        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/contact-queries')
            ->assertOk()
            ->assertJsonPath('data.0.email', 'client@example.com');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/contact-queries/{$query->id}/review")
            ->assertOk()
            ->assertJsonPath('data.status', 'REVIEWED');
    }
}
