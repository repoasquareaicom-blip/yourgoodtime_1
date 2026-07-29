<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendOtpMailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:254'],
            'otp' => ['required', 'regex:/^\d{6}$/'],
            'expires_minutes' => ['required', 'integer', 'min:1', 'max:15'],
            'purpose' => ['required', 'string', 'in:login,signup,email_verification,password_reset'],
        ];
    }
}
