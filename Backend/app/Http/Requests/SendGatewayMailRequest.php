<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendGatewayMailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to' => ['required', 'email', 'max:254'],
            'subject' => ['required', 'string', 'max:200'],
            'html' => ['required', 'string', 'max:200000'],
            'text' => ['required', 'string', 'max:200000'],
            'reply_to' => ['nullable', 'email', 'max:254'],
            'attachments' => ['nullable', 'array', 'max:1'],
            'attachments.*.name' => ['required', 'string', 'max:255'],
            'attachments.*.mime' => ['required', 'string', 'max:120'],
            'attachments.*.content' => ['required', 'string', 'max:3500000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach ($this->input('attachments', []) as $index => $file) {
                $raw = base64_decode((string) ($file['content'] ?? ''), true);

                if ($raw === false) {
                    $validator->errors()->add("attachments.$index.content", 'Attachment encoding is invalid.');
                    continue;
                }

                if (strlen($raw) > 2 * 1024 * 1024) {
                    $validator->errors()->add("attachments.$index.content", 'Attachment must be 2 MB or smaller.');
                }
            }
        });
    }
}
