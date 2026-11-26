<?php

namespace App\Http\Controllers;

use App\Models\ContactQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class AdminContactQueryController extends Controller
{
    public function index(): JsonResponse
    {
        $queries = ContactQuery::query()
            ->with('user:id,name,email')
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (ContactQuery $query): array => $this->payload($query))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $queries,
        ]);
    }

    public function show(ContactQuery $contactQuery): JsonResponse
    {
        $contactQuery->load('user:id,name,email');

        return response()->json([
            'success' => true,
            'data' => $this->payload($contactQuery),
        ]);
    }

    public function markReviewed(ContactQuery $contactQuery): JsonResponse
    {
        $contactQuery->update([
            'status' => 'REVIEWED',
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->payload($contactQuery->fresh('user')),
        ]);
    }

    public function attachment(ContactQuery $contactQuery)
    {
        if (! $contactQuery->hasAttachment() || ! Storage::disk('local')->exists($contactQuery->attachment_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Attachment is unavailable.',
            ], 404);
        }

        return Storage::disk('local')->download(
            $contactQuery->attachment_path,
            $contactQuery->attachment_original_name ?: 'attachment',
            array_filter([
                'Content-Type' => $contactQuery->attachment_mime_type,
            ]),
        );
    }

    private function payload(ContactQuery $query): array
    {
        return [
            'id' => $query->id,
            'email' => $query->email,
            'phone' => $query->phone,
            'message' => $query->message,
            'status' => $query->status,
            'has_attachment' => $query->hasAttachment(),
            'attachment_name' => $query->attachment_original_name,
            'attachment_size' => $query->attachment_size,
            'registered_user' => $query->user ? [
                'id' => $query->user->id,
                'name' => $query->user->name,
                'email' => $query->user->email,
            ] : null,
            'reviewed_at' => $query->reviewed_at?->toJSON(),
            'created_at' => $query->created_at?->toJSON(),
        ];
    }
}
