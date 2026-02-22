<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;

class SmsLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator|\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'errors' => $validator->errors(),
            'status' => true,
        ], 422));
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^(0)?9\d{9}$/'],
        ];
    }

    /**
     * Get normalized phone (10 digits: 9xxxxxxxxx) for DB lookup and SMS API.
     */
    public function getNormalizedPhone(): string
    {
        $phone = preg_replace('/\D/', '', $this->input('phone'));
        return (strlen($phone) === 11 && $phone[0] === '0') ? substr($phone, 1) : $phone;
    }
}
