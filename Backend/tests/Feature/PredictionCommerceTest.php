<?php

namespace Tests\Feature;

use App\Models\PaymentTransaction;
use App\Models\PredictionEntitlement;
use App\Models\PredictionProduct;
use App\Models\PredictionRequest;
use App\Models\PredictionResult;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PredictionCommerceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.razorpay.key_id' => null,
            'services.razorpay.key_secret' => null,
            'prediction.excel_path' => $this->predictionDataDirectory(),
        ]);
        File::deleteDirectory($this->predictionDataDirectory());
        $this->fakePredictionWorkbooks(['1998', '1999', '2000', '2001', '2002', '2003', '2004', '2005', '2006', '2007']);
    }

    public function test_future_access_returns_configured_product_price(): void
    {
        config(['prediction.future_years' => 40]);
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $request = $this->predictionRequest(['email' => $user->email]);
        $this->futureProduct();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/predictions/future-access?prediction_request_id={$request->id}&request_uuid={$request->request_uuid}")
            ->assertOk()
            ->assertJsonPath('product.product_id', 1)
            ->assertJsonPath('product.description', 'Unlock future guidance.')
            ->assertJsonPath('product.amount_minor', 60000)
            ->assertJsonPath('product.currency', 'INR')
            ->assertJsonPath('product.tax_inclusive', true)
            ->assertJsonPath('product.future_years', 40)
            ->assertJsonPath('product.duration_label', 'Next 40 Years');
    }

    public function test_active_future_product_lookup_returns_backend_controlled_offer(): void
    {
        config(['prediction.future_years' => 40]);
        $this->futureProduct();

        $this->getJson('/api/prediction-products/future/active')
            ->assertOk()
            ->assertJsonPath('product.product_id', 1)
            ->assertJsonPath('product.title', 'Future Prediction')
            ->assertJsonPath('product.description', 'Unlock future guidance.')
            ->assertJsonPath('product.amount_minor', 60000)
            ->assertJsonPath('product.currency', 'INR')
            ->assertJsonPath('product.duration_label', 'Next 40 Years');
    }

    public function test_frontend_supplied_amount_is_ignored_when_creating_order(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $request = $this->predictionRequest(['email' => $user->email]);
        $product = $this->futureProduct(['price_inclusive_tax_minor' => 60000]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/future-prediction/order', [
            'prediction_request_id' => $request->id,
            'request_uuid' => $request->request_uuid,
            'product_id' => $product->id,
            'amount_minor' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('data.product.amount_minor', 60000);

        $this->assertDatabaseHas('payment_transactions', [
            'prediction_request_id' => $request->id,
            'amount_minor' => 60000,
            'status' => 'PENDING',
        ]);
    }

    public function test_valid_payment_signature_activates_future_entitlement(): void
    {
        config(['prediction.future_years' => 40]);
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $request = $this->predictionRequest(['email' => $user->email]);
        $product = $this->futureProduct();
        $order = $this->actingAs($user, 'sanctum')->postJson('/api/payments/future-prediction/order', [
            'prediction_request_id' => $request->id,
            'request_uuid' => $request->request_uuid,
            'product_id' => $product->id,
        ])->json('data.razorpay_order_id');

        $this->actingAs($user, 'sanctum')->postJson('/api/payments/razorpay/verify', [
            'razorpay_order_id' => $order,
            'razorpay_payment_id' => 'pay_test_123',
            'razorpay_signature' => 'test_signature',
        ])
            ->assertOk()
            ->assertJsonPath('future_prediction_enabled', true);

        $this->assertDatabaseHas('payment_transactions', [
            'provider_order_id' => $order,
            'provider_payment_id' => 'pay_test_123',
            'user_id' => $user->id,
            'status' => 'CAPTURED',
        ]);
        $this->assertDatabaseHas('prediction_entitlements', [
            'prediction_request_id' => $request->id,
            'user_id' => $user->id,
            'prediction_type' => 'future',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_repeat_future_purchases_allocate_next_single_year_blocks(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 7, 29, 12, 0, 0, 'Asia/Kolkata'));
        config(['prediction.future_years' => 1]);
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $request = $this->predictionRequest([
            'email' => $user->email,
            'date_of_birth' => '1974-01-01',
        ]);
        $product = $this->futureProduct();

        foreach ([2027, 2028, 2029] as $index => $year) {
            $order = $this->actingAs($user, 'sanctum')->postJson('/api/payments/future-prediction/order', [
                'prediction_request_id' => $request->id,
                'request_uuid' => $request->request_uuid,
                'product_id' => $product->id,
            ])
                ->assertOk()
                ->assertJsonPath('data.next_purchase.start_year', $year)
                ->json('data.razorpay_order_id');

            $this->actingAs($user, 'sanctum')->postJson('/api/payments/razorpay/verify', [
                'razorpay_order_id' => $order,
                'razorpay_payment_id' => 'pay_repeat_'.$index,
                'razorpay_signature' => 'test_signature',
            ])
                ->assertOk()
                ->assertJsonPath('purchased_block.start_year', $year)
                ->assertJsonPath('purchased_block.end_year', $year)
                ->assertJsonPath('purchased_block.start_age', 53 + $index);
        }

        $this->assertSame(1, User::query()->where('email', 'ravi@example.com')->count());
        $this->assertSame([2027, 2028, 2029], PredictionEntitlement::query()->where('user_id', $user->id)->orderBy('access_start_year')->pluck('access_start_year')->all());
        CarbonImmutable::setTestNow();
    }

    public function test_repeat_future_purchases_allocate_next_excel_mapped_multi_year_blocks(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 7, 29, 12, 0, 0, 'Asia/Kolkata'));
        config(['prediction.future_years' => 5]);
        $this->fakePredictionWorkbooks(['1998-2002', '2003-2007']);
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $request = $this->predictionRequest([
            'email' => $user->email,
            'date_of_birth' => '1976-01-01',
        ]);
        $product = $this->futureProduct();

        foreach ([[2027, 2031], [2032, 2036]] as $index => [$start, $end]) {
            $order = $this->actingAs($user, 'sanctum')->postJson('/api/payments/future-prediction/order', [
                'prediction_request_id' => $request->id,
                'request_uuid' => $request->request_uuid,
                'product_id' => $product->id,
            ])
                ->assertOk()
                ->assertJsonPath('data.next_purchase.start_year', $start)
                ->assertJsonPath('data.next_purchase.end_year', $end)
                ->json('data.razorpay_order_id');

            $this->actingAs($user, 'sanctum')->postJson('/api/payments/razorpay/verify', [
                'razorpay_order_id' => $order,
                'razorpay_payment_id' => 'pay_five_'.$index,
                'razorpay_signature' => 'test_signature',
            ])->assertOk();
        }

        $this->assertDatabaseHas('prediction_entitlements', [
            'user_id' => $user->id,
            'access_start_year' => 2027,
            'access_end_year' => 2031,
            'start_age' => 51,
            'end_age' => 55,
        ]);
        $this->assertDatabaseHas('prediction_entitlements', [
            'user_id' => $user->id,
            'access_start_year' => 2032,
            'access_end_year' => 2036,
            'start_age' => 56,
            'end_age' => 60,
        ]);
        CarbonImmutable::setTestNow();
    }

    public function test_future_order_is_not_created_when_no_supported_years_remain(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 7, 29, 12, 0, 0, 'Asia/Kolkata'));
        config(['prediction.future_years' => 1]);
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $request = $this->predictionRequest([
            'email' => $user->email,
            'date_of_birth' => '1965-01-01',
        ]);
        $product = $this->futureProduct();

        $this->actingAs($user, 'sanctum')->postJson('/api/payments/future-prediction/order', [
            'prediction_request_id' => $request->id,
            'request_uuid' => $request->request_uuid,
            'product_id' => $product->id,
        ])
            ->assertStatus(409)
            ->assertJsonPath('status', 'NO_NEW_FUTURE_YEARS_AVAILABLE');

        $this->assertDatabaseCount('payment_transactions', 0);
        CarbonImmutable::setTestNow();
    }

    public function test_repeated_payment_verification_is_idempotent(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 7, 29, 12, 0, 0, 'Asia/Kolkata'));
        config(['prediction.future_years' => 1]);
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $request = $this->predictionRequest([
            'email' => $user->email,
            'date_of_birth' => '1974-01-01',
        ]);
        $product = $this->futureProduct();
        $order = $this->actingAs($user, 'sanctum')->postJson('/api/payments/future-prediction/order', [
            'prediction_request_id' => $request->id,
            'request_uuid' => $request->request_uuid,
            'product_id' => $product->id,
        ])->json('data.razorpay_order_id');

        $payload = [
            'razorpay_order_id' => $order,
            'razorpay_payment_id' => 'pay_idempotent',
            'razorpay_signature' => 'test_signature',
        ];

        $this->actingAs($user, 'sanctum')->postJson('/api/payments/razorpay/verify', $payload)->assertOk();
        $this->actingAs($user, 'sanctum')->postJson('/api/payments/razorpay/verify', $payload)->assertOk();

        $this->assertDatabaseCount('prediction_entitlements', 1);
        $this->assertDatabaseHas('prediction_entitlements', [
            'user_id' => $user->id,
            'access_start_year' => 2027,
            'access_end_year' => 2027,
        ]);
        CarbonImmutable::setTestNow();
    }

    public function test_future_order_requires_active_product_id(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $request = $this->predictionRequest(['email' => $user->email]);

        $this->actingAs($user, 'sanctum')->postJson('/api/payments/future-prediction/order', [
            'prediction_request_id' => $request->id,
            'request_uuid' => $request->request_uuid,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_id']);

        $inactiveProduct = $this->futureProduct(['is_active' => false]);

        $this->actingAs($user, 'sanctum')->postJson('/api/payments/future-prediction/order', [
            'prediction_request_id' => $request->id,
            'request_uuid' => $request->request_uuid,
            'product_id' => $inactiveProduct->id,
        ])->assertNotFound();
    }

    public function test_invalid_payment_signature_does_not_activate_entitlement(): void
    {
        config(['services.razorpay.key_secret' => 'secret']);
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $request = $this->predictionRequest(['email' => $user->email]);
        $product = $this->futureProduct();
        $order = $this->actingAs($user, 'sanctum')->postJson('/api/payments/future-prediction/order', [
            'prediction_request_id' => $request->id,
            'request_uuid' => $request->request_uuid,
            'product_id' => $product->id,
        ])->json('data.razorpay_order_id');

        $this->actingAs($user, 'sanctum')->postJson('/api/payments/razorpay/verify', [
            'razorpay_order_id' => $order,
            'razorpay_payment_id' => 'pay_bad',
            'razorpay_signature' => 'bad',
        ])->assertStatus(422);

        $this->assertDatabaseCount('prediction_entitlements', 0);
    }

    public function test_active_entitlement_allows_next_future_period_purchase(): void
    {
        config(['prediction.future_years' => 1]);
        $user = User::factory()->create(['email' => 'ravi@example.com']);
        $request = $this->predictionRequest(['email' => $user->email]);
        $product = $this->futureProduct();
        $this->entitlement($request, $product, now()->addYear(), $user, [
            'access_start_year' => 2027,
            'access_end_year' => 2027,
            'start_age' => 54,
            'end_age' => 54,
            'purchased_years' => 1,
            'allocated_year_count' => 1,
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/payments/future-prediction/order', [
            'prediction_request_id' => $request->id,
            'request_uuid' => $request->request_uuid,
            'product_id' => $product->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.next_purchase.start_year', 2028)
            ->assertJsonPath('data.next_purchase.end_year', 2028);
    }

    public function test_hardcoded_payment_phone_number_is_not_in_production_source(): void
    {
        $source = collect([
            base_path('app'),
            base_path('config'),
            base_path('database'),
            base_path('../Frontend/src'),
        ])->flatMap(fn (string $path) => File::allFiles($path))
            ->filter(fn ($file) => in_array($file->getExtension(), ['php', 'js', 'jsx'], true))
            ->map(fn ($file) => File::get($file->getPathname()))
            ->implode("\n");

        $this->assertStringNotContainsString('9894295995', $source);
    }

    public function test_missing_user_phone_omits_razorpay_contact_prefill(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com', 'phone_e164' => null]);
        $request = $this->predictionRequest(['email' => $user->email]);
        $product = $this->futureProduct();

        $this->actingAs($user, 'sanctum')->postJson('/api/payments/future-prediction/order', [
            'prediction_request_id' => $request->id,
            'request_uuid' => $request->request_uuid,
            'product_id' => $product->id,
        ])
            ->assertOk()
            ->assertJsonMissingPath('data.prefill.contact');
    }

    public function test_existing_user_phone_is_used_for_razorpay_contact_prefill(): void
    {
        $user = User::factory()->create(['email' => 'ravi@example.com', 'phone_e164' => '+14155552671']);
        $request = $this->predictionRequest(['email' => $user->email]);
        $product = $this->futureProduct();

        $this->actingAs($user, 'sanctum')->postJson('/api/payments/future-prediction/order', [
            'prediction_request_id' => $request->id,
            'request_uuid' => $request->request_uuid,
            'product_id' => $product->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.prefill.contact', '+14155552671');
    }

    public function test_backend_fetches_payment_entity_and_stores_contact_for_authenticated_user(): void
    {
        config(['services.razorpay.key_id' => 'key', 'services.razorpay.key_secret' => 'secret']);
        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response(['id' => 'order_contact'], 200),
            'api.razorpay.com/v1/payments/pay_contact' => Http::response([
                'id' => 'pay_contact',
                'order_id' => 'order_contact',
                'status' => 'captured',
                'contact' => '+14155552671',
                'email' => 'payer@example.com',
                'created_at' => now()->timestamp,
            ], 200),
        ]);

        $user = User::factory()->create(['email' => 'ravi@example.com', 'phone_e164' => null]);
        $request = $this->predictionRequest(['email' => $user->email]);
        $product = $this->futureProduct();
        $order = $this->actingAs($user, 'sanctum')->postJson('/api/payments/future-prediction/order', [
            'prediction_request_id' => $request->id,
            'request_uuid' => $request->request_uuid,
            'product_id' => $product->id,
        ])->json('data.razorpay_order_id');

        $this->actingAs($user, 'sanctum')->postJson('/api/payments/razorpay/verify', [
            'razorpay_order_id' => $order,
            'razorpay_payment_id' => 'pay_contact',
            'razorpay_signature' => hash_hmac('sha256', $order.'|pay_contact', 'secret'),
        ])->assertOk();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/payments/pay_contact'));
        $this->assertDatabaseHas('payment_transactions', [
            'provider_order_id' => $order,
            'payment_contact' => '+14155552671',
            'payment_email' => 'payer@example.com',
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'phone_e164' => '+14155552671',
            'phone_source' => 'razorpay',
            'phone_verified_at' => null,
        ]);
        $this->assertDatabaseHas('prediction_entitlements', [
            'prediction_request_id' => $request->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_payment_contact_cannot_change_another_user(): void
    {
        $owner = User::factory()->create(['email' => 'ravi@example.com']);
        $other = User::factory()->create(['email' => 'other@example.com']);
        $request = $this->predictionRequest(['email' => $owner->email]);
        $product = $this->futureProduct();
        $order = $this->actingAs($owner, 'sanctum')->postJson('/api/payments/future-prediction/order', [
            'prediction_request_id' => $request->id,
            'request_uuid' => $request->request_uuid,
            'product_id' => $product->id,
        ])->json('data.razorpay_order_id');

        $this->actingAs($other, 'sanctum')->postJson('/api/payments/razorpay/verify', [
            'razorpay_order_id' => $order,
            'razorpay_payment_id' => 'pay_contact',
            'razorpay_signature' => 'test_signature',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('payment_transactions', [
            'provider_order_id' => $order,
            'user_id' => $other->id,
            'status' => 'CAPTURED',
        ]);
    }

    public function test_unparseable_contact_does_not_fail_payment_or_overwrite_existing_phone(): void
    {
        config(['services.razorpay.key_id' => 'key', 'services.razorpay.key_secret' => 'secret']);
        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response(['id' => 'order_bad_contact'], 200),
            'api.razorpay.com/v1/payments/pay_bad_contact' => Http::response([
                'id' => 'pay_bad_contact',
                'order_id' => 'order_bad_contact',
                'status' => 'captured',
                'contact' => 'local-number',
                'email' => 'payer@example.com',
            ], 200),
        ]);

        $user = User::factory()->create(['email' => 'ravi@example.com', 'phone_e164' => '+14155550000']);
        $request = $this->predictionRequest(['email' => $user->email]);
        $product = $this->futureProduct();
        $order = $this->actingAs($user, 'sanctum')->postJson('/api/payments/future-prediction/order', [
            'prediction_request_id' => $request->id,
            'request_uuid' => $request->request_uuid,
            'product_id' => $product->id,
        ])->json('data.razorpay_order_id');

        $this->actingAs($user, 'sanctum')->postJson('/api/payments/razorpay/verify', [
            'razorpay_order_id' => $order,
            'razorpay_payment_id' => 'pay_bad_contact',
            'razorpay_signature' => hash_hmac('sha256', $order.'|pay_bad_contact', 'secret'),
        ])->assertOk();

        $this->assertSame('+14155550000', $user->refresh()->phone_e164);
        $this->assertDatabaseHas('payment_transactions', [
            'provider_order_id' => $order,
            'payment_contact' => 'local-number',
            'status' => 'CAPTURED',
        ]);
    }

    public function test_webhook_backfills_missing_contact_and_is_idempotent(): void
    {
        config(['services.razorpay.webhook_secret' => 'whsec']);
        $user = User::factory()->create(['email' => 'ravi@example.com', 'phone_e164' => null]);
        $request = $this->predictionRequest(['email' => $user->email]);
        $product = $this->futureProduct();
        $transaction = $this->transaction($request, $product, $user, 'order_webhook');
        $payload = [
            'id' => 'evt_1',
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_webhook',
                        'order_id' => 'order_webhook',
                        'contact' => '+442071838750',
                        'email' => 'payer@example.com',
                        'created_at' => now()->timestamp,
                    ],
                ],
            ],
        ];
        $raw = json_encode($payload);

        $this->withHeader('X-Razorpay-Signature', hash_hmac('sha256', $raw, 'whsec'))
            ->postJson('/api/webhooks/razorpay', $payload)
            ->assertOk();
        $this->withHeader('X-Razorpay-Signature', hash_hmac('sha256', $raw, 'whsec'))
            ->postJson('/api/webhooks/razorpay', $payload)
            ->assertOk();

        $this->assertDatabaseCount('prediction_entitlements', 1);
        $this->assertDatabaseHas('payment_transactions', [
            'id' => $transaction->id,
            'payment_contact' => '+442071838750',
            'payment_email' => 'payer@example.com',
            'provider_payment_id' => 'pay_webhook',
        ]);
        $this->assertSame('+442071838750', $user->refresh()->phone_e164);
    }

    public function test_feedback_submission_validates_and_persists(): void
    {
        $request = $this->predictionRequest();
        $result = PredictionResult::query()->create([
            'prediction_request_id' => $request->id,
            'prediction_type' => 'past',
            'identity_video_id' => '34',
            'result_payload' => ['type' => 'past'],
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now(),
        ]);

        $this->postJson("/api/predictions/{$request->id}/feedback", [
            'request_uuid' => $request->request_uuid,
            'prediction_result_id' => $result->id,
            'prediction_type' => 'past',
            'rating' => 5,
            'accuracy_band' => 'ABOVE_OR_EQUAL_90',
            'comments' => 'Matched closely.',
        ])->assertOk();

        $this->assertDatabaseHas('prediction_feedback', [
            'prediction_request_id' => $request->id,
            'prediction_result_id' => $result->id,
            'rating' => 5,
            'accuracy_band' => 'ABOVE_OR_EQUAL_90',
        ]);

        $this->postJson("/api/predictions/{$request->id}/feedback", [
            'request_uuid' => $request->request_uuid,
            'prediction_result_id' => $result->id,
            'prediction_type' => 'past',
            'rating' => 6,
            'accuracy_band' => 'WRONG',
        ])->assertJsonValidationErrors(['rating', 'accuracy_band']);
    }

    private function predictionRequest(array $overrides = []): PredictionRequest
    {
        return PredictionRequest::query()->create(array_merge([
            'request_uuid' => (string) Str::uuid(),
            'prediction_type' => 'past',
            'status' => 'completed',
            'full_name' => 'Ravi Kumar',
            'date_of_birth' => '1973-12-16',
            'time_of_birth' => '06:45',
            'timezone' => 'Asia/Kolkata',
            'place_of_birth' => 'Chennai',
            'latitude' => 13.0827,
            'longitude' => 80.2707,
            'birth_payload' => [],
            'raw_astrology_response' => [],
            'normalized_astrology_data' => $this->normalizedData(),
            'submitted_at' => now(),
            'completed_at' => now(),
            'email' => 'ravi@example.com',
        ], $overrides));
    }

    private function normalizedData(): array
    {
        return [
            'birth' => [
                'date_of_birth' => '1973-12-16',
                'time_of_birth' => '06:45',
                'place_of_birth' => 'Chennai',
                'latitude' => 13.0827,
                'longitude' => 80.2707,
            ],
            'identity' => [
                'lagnam' => 'Aries',
                'rasi' => 'Pisces',
                'nakshatra' => 'Swati',
            ],
            'dasha' => [
                'current_major_lord' => 'Venus',
                'current_minor_lord' => 'Moon',
                'major' => [
                    ['planet' => 'Venus', 'planet_id' => 5, 'start' => '1-1-2027 00:00', 'end' => '31-12-2036 23:59'],
                ],
            ],
            'planets' => [
                ['id' => 5, 'name' => 'Venus', 'house' => 6],
            ],
        ];
    }

    private function fakePredictionWorkbooks(array $yearHeaders): void
    {
        $base = $this->predictionDataDirectory();
        $futureDirectory = $base.DIRECTORY_SEPARATOR.'future';

        if (! is_dir($futureDirectory)) {
            mkdir($futureDirectory, 0777, true);
        }

        if (! is_dir($base)) {
            mkdir($base, 0777, true);
        }

        $intro = new Spreadsheet();
        $intro->getActiveSheet()->fromArray([
            ['Nakshatra', 'Rasi', 'Lagnam', 'VideoId'],
            ['Swati', 'Pisces', 'Aries', 'INTRO_001'],
        ]);
        (new Xlsx($intro))->save($base.DIRECTORY_SEPARATOR.'nakshatra_lagnam.xlsx');

        $row = ['MEENAM', '6'];
        foreach ($yearHeaders as $index => $yearHeader) {
            $row[] = $yearHeader;
            $row[] = 'FUTURE_'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
        }

        $future = new Spreadsheet();
        $future->getActiveSheet()->setTitle('VENUS');
        $future->getActiveSheet()->fromArray([
            ['VENUS DASA', 'POSITION'],
            ['LAGNAM', ''],
            ['MESHAM', ''],
            $row,
        ]);
        (new Xlsx($future))->save($futureDirectory.DIRECTORY_SEPARATOR.'FUTURE_41 TO 60.xlsx');
    }

    private function predictionDataDirectory(): string
    {
        return storage_path('framework/testing/prediction-commerce-data');
    }

    private function futureProduct(array $overrides = []): PredictionProduct
    {
        return PredictionProduct::query()->create(array_merge([
            'code' => 'FUTURE_PREDICTION',
            'name' => 'Future Prediction',
            'description' => 'Unlock future guidance.',
            'prediction_type' => 'future',
            'price_inclusive_tax_minor' => 60000,
            'currency' => 'INR',
            'tax_inclusive' => true,
            'is_active' => true,
        ], $overrides));
    }

    private function transaction(PredictionRequest $request, PredictionProduct $product, User $user, ?string $orderId = null): PaymentTransaction
    {
        return PaymentTransaction::query()->create([
            'prediction_request_id' => $request->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'provider' => 'razorpay',
            'currency' => 'INR',
            'amount_minor' => 60000,
            'tax_inclusive' => true,
            'status' => 'CAPTURED',
            'provider_order_id' => $orderId ?? 'order_'.Str::random(8),
        ]);
    }

    private function entitlement(PredictionRequest $request, PredictionProduct $product, mixed $expiresAt, User $user, array $overrides = []): PredictionEntitlement
    {
        $transaction = $this->transaction($request, $product, $user);
        return PredictionEntitlement::query()->create(array_merge([
            'prediction_request_id' => $request->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'payment_transaction_id' => $transaction->id,
            'prediction_type' => 'future',
            'purchased_years' => 40,
            'access_start_year' => now()->year + 1,
            'access_end_year' => now()->year + 5,
            'starts_at' => now(),
            'expires_at' => $expiresAt,
            'status' => 'ACTIVE',
        ], $overrides));
    }
}
