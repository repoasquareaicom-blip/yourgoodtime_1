<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\AppointmentPaymentMethod;
use App\Models\PaymentMethodSetting;
use App\Models\PredictionRequest;
use App\Models\PredictionProduct;
use App\Models\TempleRemedyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class TempleRemedyController extends Controller
{
    private const PRODUCT_CODE = 'TEMPLE_REMEDY';

    public function config(): JsonResponse
    {
        $product = $this->activeProduct();

        return response()->json([
            'success' => true,
            'data' => [
                'available' => $product !== null,
                'product_id' => $product?->id,
                'title' => $product?->name ?? 'Temple Remedy',
                'description' => $product?->description ?? 'Temple remedy guidance request.',
                'amount_minor' => (int) ($product?->price_inclusive_tax_minor ?? 0),
                'currency' => $product?->currency ?? 'INR',
                'tax_inclusive' => (bool) ($product?->tax_inclusive ?? true),
                'options' => $this->remedyOptions(),
                'languages' => $this->languageOptions(),
                'payment_methods' => $this->paymentMethods(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $items = TempleRemedyRequest::query()
            ->where('user_id', $request->user()->id)
            ->whereNotIn('status', ['PAYMENT_CANCELLED', 'CANCELLED'])
            ->where(function ($query): void {
                $query
                    ->where('payment_method_code', '!=', AppointmentPaymentMethod::PAYMENT_GATEWAY)
                    ->orWhere('payment_status', 'PAID')
                    ->orWhere('status', 'COMPLETED');
            })
            ->latest('submitted_at')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (TempleRemedyRequest $remedyRequest): array => $this->payload($remedyRequest))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $activePaymentMethodCodes = $this->activePaymentMethodCodes();

        if (! $activePaymentMethodCodes) {
            return response()->json([
                'success' => false,
                'message' => 'Payment options are currently unavailable. Please try again later.',
                'errors' => ['payment_method' => ['Payment options are currently unavailable. Please try again later.']],
            ], 422);
        }

        $validated = $request->validate([
            'remedy_option_code' => ['required', 'string', 'max:80'],
            'problem_text' => ['nullable', 'string', 'max:3000'],
            'contact_number' => ['required', 'string', 'max:32', 'regex:/^[0-9+()\\-\\s]{7,32}$/'],
            'alternate_contact_number' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\\-\\s]{7,32}$/'],
            'preferred_language' => ['required', 'in:ta,en'],
            'payment_method' => ['required', 'in:'.implode(',', $activePaymentMethodCodes)],
            'payment_screenshot' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $option = collect($this->remedyOptions())->firstWhere('code', $validated['remedy_option_code']);
        if (! $option) {
            return response()->json([
                'success' => false,
                'message' => 'Select a valid Temple Remedy option.',
                'errors' => ['remedy_option_code' => ['Select a valid Temple Remedy option.']],
            ], 422);
        }

        if ($validated['payment_method'] === AppointmentPaymentMethod::QR_ONLINE && ! $request->hasFile('payment_screenshot')) {
            return response()->json([
                'success' => false,
                'message' => 'Upload the payment screenshot to continue.',
                'errors' => ['payment_screenshot' => ['Upload the payment screenshot to continue.']],
            ], 422);
        }

        $product = $this->activeProduct();
        if (! $product) {
            return response()->json(['success' => false, 'message' => 'Temple Remedy is not available right now.'], 404);
        }

        $user = $request->user();
        $duplicateRequest = $this->recentDuplicateRequest($user->id, $validated, $option['code']);
        if ($duplicateRequest) {
            return response()->json([
                'success' => true,
                'message' => 'Your Temple Remedy request has already been submitted.',
                'data' => $this->payload($duplicateRequest),
            ]);
        }

        if ($this->hasActiveRequest($user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'You already have an active Temple Remedy request. You can request another remedy after the current one is completed.',
                'errors' => ['temple_remedy' => ['Only one Temple Remedy request can be active at a time.']],
            ], 409);
        }

        $manualPayment = null;
        if ($validated['payment_method'] === AppointmentPaymentMethod::QR_ONLINE) {
            $manualPayment = array_filter([
                'uploaded_at' => now()->toJSON(),
                'review_status' => 'waiting_admin_confirmation',
            ]);

            if ($request->hasFile('payment_screenshot')) {
                $file = $request->file('payment_screenshot');
                $manualPayment = array_merge($manualPayment, [
                    'path' => $file->store('temple-remedy-manual-payments'),
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                ]);
            }
        }

        $remedyRequest = TempleRemedyRequest::query()->create([
            'user_id' => $user->id,
            'prediction_request_id' => PredictionRequest::query()
                ->where('email', $user->email)
                ->latest('submitted_at')
                ->latest('id')
                ->value('id'),
            'remedy_option_code' => $option['code'],
            'remedy_option_title' => $option['title'],
            'problem_text' => trim((string) ($validated['problem_text'] ?? '')),
            'contact_number' => trim((string) $validated['contact_number']),
            'alternate_contact_number' => isset($validated['alternate_contact_number']) ? trim((string) $validated['alternate_contact_number']) : null,
            'preferred_language' => $validated['preferred_language'],
            'payment_method_code' => $validated['payment_method'],
            'manual_payment' => $manualPayment,
            'status' => 'PAYMENT_PENDING',
            'payment_enabled' => true,
            'amount_minor' => (int) $product->price_inclusive_tax_minor,
            'currency' => $product->currency ?: 'INR',
            'payment_status' => 'PENDING',
            'payment_enabled_at' => now(),
            'submitted_at' => now(),
        ]);

        $this->sendThankYouMail($user->email, $user->name, $remedyRequest);

        return response()->json([
            'success' => true,
            'message' => 'Your Temple Remedy request has been submitted successfully.',
            'data' => $this->payload($remedyRequest),
        ], 201);
    }

    public function cancelPayment(Request $request, TempleRemedyRequest $templeRemedy): JsonResponse
    {
        abort_unless($templeRemedy->user_id === $request->user()->id, 403);

        if ($templeRemedy->payment_status === 'PAID' || $templeRemedy->status === 'COMPLETED') {
            return response()->json([
                'success' => false,
                'message' => 'This Temple Remedy request cannot be cancelled.',
            ], 409);
        }

        if ($templeRemedy->payment_method_code !== AppointmentPaymentMethod::PAYMENT_GATEWAY) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending payment gateway requests can be cancelled.',
            ], 409);
        }

        $templeRemedy->forceFill([
            'status' => 'PAYMENT_CANCELLED',
            'payment_status' => 'FAILED',
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Temple Remedy payment was cancelled.',
            'data' => $this->payload($templeRemedy->fresh()),
        ]);
    }

    public function feedback(Request $request, TempleRemedyRequest $templeRemedy): JsonResponse
    {
        abort_unless($templeRemedy->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        $templeRemedy->forceFill([
            'feedback_rating' => (int) $validated['rating'],
            'feedback_comments' => isset($validated['comments']) ? trim((string) $validated['comments']) : null,
            'feedback_submitted_at' => now(),
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Thank you for your Temple Remedy feedback.',
            'data' => $this->payload($templeRemedy->fresh()),
        ]);
    }

    public function file(Request $request, TempleRemedyRequest $templeRemedy)
    {
        $user = $request->user();
        $isAdmin = $user instanceof AdminUser;
        abort_unless($isAdmin || $templeRemedy->user_id === $user->id, 403);

        if (! $isAdmin && $templeRemedy->status !== 'COMPLETED') {
            return response()->json([
                'success' => false,
                'message' => 'This remedy is not yet available.',
            ], 404);
        }

        if ($templeRemedy->remedy_file_path) {
            $disk = Storage::disk('local');
            if (! $disk->exists($templeRemedy->remedy_file_path)) {
                $disk = Storage::disk('public');
            }

            if (! $disk->exists($templeRemedy->remedy_file_path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The requested file is unavailable.',
                ], 404);
            }

            $filename = $templeRemedy->remedy_file_name ?: basename($templeRemedy->remedy_file_path);
            $headers = array_filter([
                'Content-Type' => $templeRemedy->remedy_mime_type,
            ]);

            return $request->boolean('download')
                ? response()->download($disk->path($templeRemedy->remedy_file_path), $filename, $headers)
                : response()->file($disk->path($templeRemedy->remedy_file_path), $headers);
        }

        if ($templeRemedy->remedy_url && $templeRemedy->status === 'COMPLETED') {
            return redirect()->away($templeRemedy->remedy_url);
        }

        return response()->json([
            'success' => false,
            'message' => 'The requested file is unavailable.',
        ], 404);
    }

    private function payload(TempleRemedyRequest $remedyRequest): array
    {
        $canShowRemedy = $this->canShowRemedy($remedyRequest);
        $fileEndpoint = url('/api/temple-remedies/'.$remedyRequest->id.'/file');

        return [
            'id' => $remedyRequest->id,
            'problem_text' => $remedyRequest->problem_text,
            'remedy_option' => [
                'code' => $remedyRequest->remedy_option_code,
                'title' => $remedyRequest->remedy_option_title,
            ],
            'contact_number' => $remedyRequest->contact_number,
            'alternate_contact_number' => $remedyRequest->alternate_contact_number,
            'preferred_language' => $remedyRequest->preferred_language,
            'payment_method' => $remedyRequest->payment_method_code,
            'status' => $remedyRequest->status,
            'status_label' => $this->statusLabel($remedyRequest->status),
            'payment_enabled' => $remedyRequest->payment_enabled,
            'amount_minor' => $remedyRequest->amount_minor,
            'currency' => $remedyRequest->currency,
            'payment_status' => $remedyRequest->payment_status,
            'can_pay' => $remedyRequest->payment_method_code === AppointmentPaymentMethod::PAYMENT_GATEWAY
                && $remedyRequest->status === 'PAYMENT_PENDING'
                && $remedyRequest->payment_status !== 'PAID',
            'submitted_at' => $remedyRequest->submitted_at?->toJSON(),
            'payment_enabled_at' => $remedyRequest->payment_enabled_at?->toJSON(),
            'paid_at' => $remedyRequest->paid_at?->toJSON(),
            'remedy' => [
                'available' => $canShowRemedy,
                'type' => $canShowRemedy ? $remedyRequest->remedy_type : null,
                'title' => $canShowRemedy ? $remedyRequest->remedy_title : null,
                'file_name' => $canShowRemedy ? $remedyRequest->remedy_file_name : null,
                'mime_type' => $canShowRemedy ? $remedyRequest->remedy_mime_type : null,
                'file_size' => $canShowRemedy ? $remedyRequest->remedy_file_size : null,
                'url' => $canShowRemedy ? $fileEndpoint : null,
                'download_url' => $canShowRemedy ? $fileEndpoint.'?download=1' : null,
                'uploaded_at' => $canShowRemedy ? $remedyRequest->remedy_uploaded_at?->toJSON() : null,
            ],
            'admin_note' => $canShowRemedy ? $remedyRequest->admin_note : null,
            'feedback' => [
                'submitted' => $remedyRequest->feedback_submitted_at !== null,
                'rating' => $remedyRequest->feedback_rating,
                'comments' => $remedyRequest->feedback_comments,
                'submitted_at' => $remedyRequest->feedback_submitted_at?->toJSON(),
            ],
        ];
    }

    private function canShowRemedy(TempleRemedyRequest $remedyRequest): bool
    {
        $hasRemedy = (bool) ($remedyRequest->remedy_url || $remedyRequest->remedy_file_path);

        if (! $hasRemedy) {
            return false;
        }

        return $remedyRequest->status === 'COMPLETED';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'RECEIVED' => 'Received',
            'REVIEWING' => 'Reviewing',
            'PAYMENT_PENDING' => 'Payment Pending',
            'COMPLETED' => 'Completed',
            default => ucwords(strtolower(str_replace('_', ' ', $status))),
        };
    }

    private function activeProduct(): ?PredictionProduct
    {
        $product = PredictionProduct::query()
            ->where('code', self::PRODUCT_CODE)
            ->where('is_active', true)
            ->first();

        return $product ?: PredictionProduct::query()->updateOrCreate(
            ['code' => self::PRODUCT_CODE],
            [
                'name' => 'Temple Remedy',
                'description' => 'Temple remedy guidance request with admin review and payment confirmation.',
                'prediction_type' => 'temple_remedy',
                'price_inclusive_tax_minor' => 100000,
                'currency' => 'INR',
                'tax_inclusive' => true,
                'tax_rate' => null,
                'is_active' => true,
                'metadata' => ['maximum_options' => 10],
            ]
        );
    }

    private function hasActiveRequest(int $userId): bool
    {
        return TempleRemedyRequest::query()
            ->where('user_id', $userId)
            ->whereNotIn('status', ['COMPLETED', 'PAYMENT_CANCELLED', 'CANCELLED'])
            ->whereNotIn('payment_status', ['FAILED', 'REFUNDED'])
            ->where(function ($query): void {
                $query
                    ->where('payment_method_code', '!=', AppointmentPaymentMethod::PAYMENT_GATEWAY)
                    ->orWhere('payment_status', 'PAID');
            })
            ->exists();
    }

    private function recentDuplicateRequest(int $userId, array $validated, string $optionCode): ?TempleRemedyRequest
    {
        return TempleRemedyRequest::query()
            ->where('user_id', $userId)
            ->where('remedy_option_code', $optionCode)
            ->where('contact_number', trim((string) $validated['contact_number']))
            ->where('preferred_language', $validated['preferred_language'])
            ->where('payment_method_code', $validated['payment_method'])
            ->whereNotIn('status', ['COMPLETED', 'PAYMENT_CANCELLED', 'CANCELLED'])
            ->whereNotIn('payment_status', ['FAILED', 'REFUNDED'])
            ->where('created_at', '>=', now()->subMinutes(5))
            ->latest('id')
            ->first();
    }

    private function remedyOptions(): array
    {
        return [
            ['code' => 'career_growth', 'title' => 'Career Growth'],
            ['code' => 'business_success', 'title' => 'Business Success'],
            ['code' => 'marriage_delay', 'title' => 'Marriage Delay'],
            ['code' => 'family_peace', 'title' => 'Family Peace'],
            ['code' => 'health_wellbeing', 'title' => 'Health and Wellbeing'],
            ['code' => 'financial_stability', 'title' => 'Financial Stability'],
            ['code' => 'education_focus', 'title' => 'Education and Focus'],
            ['code' => 'property_vehicle', 'title' => 'Property or Vehicle'],
            ['code' => 'child_blessing', 'title' => 'Child Blessing'],
            ['code' => 'general_obstacles', 'title' => 'General Obstacles'],
        ];
    }

    private function languageOptions(): array
    {
        return [
            ['code' => 'ta', 'name' => 'Tamil'],
            ['code' => 'en', 'name' => 'English'],
        ];
    }

    private function paymentMethods(): array
    {
        $setting = $this->paymentMethodSetting();

        return array_values(array_filter([
            $setting->payment_gateway_enabled ? [
                'code' => AppointmentPaymentMethod::PAYMENT_GATEWAY,
                'name' => 'Pay Online',
                'subtitle' => 'Existing Payment Gateway',
                'instructions' => 'Pay securely through Razorpay.',
            ] : null,
            $setting->gpay_qr_enabled && $setting->qr_image_path ? [
                'code' => AppointmentPaymentMethod::QR_ONLINE,
                'name' => 'Pay via GPay QR',
                'subtitle' => 'Scan QR and submit proof',
                'instructions' => 'Scan the QR code, complete payment, then upload the payment screenshot.',
                'qr_image_url' => $setting->qrImageUrl(),
            ] : null,
        ]));
    }

    private function activePaymentMethodCodes(): array
    {
        return collect($this->paymentMethods())->pluck('code')->values()->all();
    }

    private function paymentMethodSetting(): PaymentMethodSetting
    {
        return PaymentMethodSetting::query()->firstOrCreate(
            ['service_type' => PaymentMethodSetting::TEMPLE_REMEDY],
            ['payment_gateway_enabled' => true, 'gpay_qr_enabled' => false]
        );
    }

    private function sendThankYouMail(string $email, ?string $name, TempleRemedyRequest $remedyRequest): void
    {
        try {
            Mail::raw(
                "Dear ".($name ?: 'Customer').",\n\n".
                "Thank you for submitting your Temple Remedy request with Your Good Time.\n\n".
                "Request ID: #{$remedyRequest->id}\n".
                "Our team will review your problem details and update the status in your My Account dashboard.\n\n".
                "Regards,\nYour Good Time",
                function ($message) use ($email): void {
                    $message->to($email)
                        ->subject('Temple Remedy request received - Your Good Time');
                }
            );
        } catch (\Throwable $exception) {
            Log::warning('Temple Remedy thank-you email failed.', [
                'request_id' => $remedyRequest->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
