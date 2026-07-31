<?php

namespace App\Http\Controllers;

use App\Models\TempleRemedyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TempleRemedyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = TempleRemedyRequest::query()
            ->where('user_id', $request->user()->id)
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
        $validated = $request->validate([
            'problem_text' => ['required', 'string', 'min:20', 'max:3000'],
        ]);

        $user = $request->user();

        $remedyRequest = TempleRemedyRequest::query()->create([
            'user_id' => $user->id,
            'problem_text' => trim((string) $validated['problem_text']),
            'status' => 'RECEIVED',
            'payment_enabled' => false,
            'payment_status' => 'NOT_REQUIRED',
            'submitted_at' => now(),
        ]);

        $this->sendThankYouMail($user->email, $user->name, $remedyRequest);

        return response()->json([
            'success' => true,
            'message' => 'Your Temple Remedy request has been submitted successfully.',
            'data' => $this->payload($remedyRequest),
        ], 201);
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

    private function payload(TempleRemedyRequest $remedyRequest): array
    {
        return [
            'id' => $remedyRequest->id,
            'problem_text' => $remedyRequest->problem_text,
            'status' => $remedyRequest->status,
            'status_label' => $this->statusLabel($remedyRequest->status),
            'payment_enabled' => $remedyRequest->payment_enabled,
            'amount_minor' => $remedyRequest->amount_minor,
            'currency' => $remedyRequest->currency,
            'payment_status' => $remedyRequest->payment_status,
            'submitted_at' => $remedyRequest->submitted_at?->toJSON(),
            'payment_enabled_at' => $remedyRequest->payment_enabled_at?->toJSON(),
            'paid_at' => $remedyRequest->paid_at?->toJSON(),
            'remedy' => [
                'available' => (bool) ($remedyRequest->remedy_url || $remedyRequest->remedy_file_path),
                'type' => $remedyRequest->remedy_type,
                'title' => $remedyRequest->remedy_title,
                'url' => $remedyRequest->remedy_url,
                'uploaded_at' => $remedyRequest->remedy_uploaded_at?->toJSON(),
            ],
            'admin_note' => $remedyRequest->admin_note,
            'feedback' => [
                'submitted' => $remedyRequest->feedback_submitted_at !== null,
                'rating' => $remedyRequest->feedback_rating,
                'comments' => $remedyRequest->feedback_comments,
                'submitted_at' => $remedyRequest->feedback_submitted_at?->toJSON(),
            ],
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'RECEIVED' => 'Request received',
            'REVIEWING' => 'Under review',
            'PAYMENT_PENDING' => 'Payment pending',
            'PAID' => 'Payment completed',
            'PREPARING_REMEDY' => 'Preparing the remedy',
            'REMEDY_READY' => 'Remedy ready',
            'COMPLETED' => 'Completed',
            default => ucwords(strtolower(str_replace('_', ' ', $status))),
        };
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
