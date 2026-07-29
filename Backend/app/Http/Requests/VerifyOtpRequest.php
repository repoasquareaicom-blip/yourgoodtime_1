<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
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
            'purpose' => ['required', 'string', 'in:login,signup,email_verification,password_reset'],
        ];
    }
}
