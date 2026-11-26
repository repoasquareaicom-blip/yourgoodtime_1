<?php

namespace App\Http\Controllers;

use App\Models\ContactQuery;
use App\Models\User;
use App\Services\ContactQueryMailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ContactQueryController extends Controller
{
    public function store(Request $request, ContactQueryMailService $mail): JsonResponse
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
            'phone' => trim((string) $request->input('phone')) ?: null,
            'message' => trim((string) $request->input('message')),
        ]);

        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'message' => ['required', 'string', 'min:8', 'max:4000'],
            'attachment' => [
                'nullable',
                'file',
                'max:2048',
                'mimes:jpg,jpeg,png,webp,pdf,doc,docx,txt',
            ],
        ], [
            'email.required' => 'Please enter your registered email ID.',
            'message.required' => 'Please enter your query.',
            'message.min' => 'Please enter a little more detail in your query.',
            'attachment.max' => 'Attachment must be 2 MB or smaller.',
            'attachment.mimes' => 'Please attach a PDF, Word, text, or image file.',
        ]);

        $email = $validated['email'];
        $phone = $validated['phone'] ?? null;
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        $query = ContactQuery::query()->create([
            'user_id' => $user?->id,
            'email' => $email,
            'phone' => $phone,
            'message' => $validated['message'],
            'status' => 'NEW',
        ]);

        $attachment = $request->file('attachment');
        if ($attachment) {
            $storedName = $query->id.'_'.Str::uuid().'.'.$attachment->getClientOriginalExtension();
            $path = $attachment->storeAs('contact-queries', $storedName, 'local');

            $query->update([
                'attachment_path' => $path,
                'attachment_original_name' => $attachment->getClientOriginalName(),
                'attachment_mime_type' => $attachment->getClientMimeType(),
                'attachment_size' => $attachment->getSize(),
            ]);
        }

        $query->refresh();

        try {
            $mail->send($query);
        } catch (Throwable $exception) {
            if ($query->attachment_path) {
                Storage::disk('local')->delete($query->attachment_path);
            }
            $query->delete();

            return response()->json([
                'success' => false,
                'message' => 'Unable to send your query right now. Please try again.',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'Thank you. We received your query and sent a confirmation email.',
            'data' => [
                'id' => $query->id,
            ],
        ], 201);
    }
}
