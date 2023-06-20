<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
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
            'address_id' => 'required|exists:addresses,uuid',
            // 'shipping_id' => 'required|exists:shippings,id', 
            'payment_method' => 'required|in:online,cash,partial', // TODO : make it dynamic
            'gateway' => 'required_if:payment_method,online|in:payping', // TODO : make it dynamic
        ];
    }
}
