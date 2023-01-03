<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'order_id' => 'required|exists:orders,uuid',
            'method' => 'nullable|in:cash,card',
            'gateway' => 'required_if:method,card|in:saman,pasargad,mellat,melli,sepah',
            'card_number' => 'required_if:payment_method,card|digits:16',
            'card_expiration' => 'required_if:payment_method,card|date_format:m/Y',
            'card_cvv' => 'required_if:payment_method,card|digits:3',
        ];
    }
}
