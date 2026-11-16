<?php

namespace App\Http\Requests\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethods;
use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize()
    {
        return true; // gated by the orders.create permission middleware
    }

    protected function prepareForValidation()
    {
        $this->merge(['phone' => Phone::normalize($this->input('phone'))]);
    }

    public function rules()
    {
        return [
            'phone' => ['required', 'regex:/^09\d{9}$/'],
            'customer_name' => ['nullable', 'string', 'max:255'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.variation_id' => ['nullable', 'integer', 'exists:variations,id'],
            'lines.*.title' => ['nullable', 'string', 'max:255', 'required_without:lines.*.variation_id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'lines.*.unit_price' => ['nullable', 'integer', 'min:0', 'required_without:lines.*.variation_id'],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.dimensions' => ['nullable', 'string', 'max:255'],
            'lines.*.color' => ['nullable', 'string', 'max:255'],

            'address' => ['required', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:255'],
            'county' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],

            'shipping_id' => ['required', 'integer', 'exists:shippings,id'],
            'shipping_price' => ['nullable', 'integer', 'min:0'],
            'discount_amount' => ['nullable', 'integer', 'min:0'],

            'channel' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(OrderStatus::names())],
            'payment_method' => ['required', Rule::in(PaymentMethods::names())],
            'notify' => ['nullable', 'boolean'],

            'payment' => ['nullable', 'array'],
            'payment.amount' => ['required_with:payment', 'integer', 'min:1'],
            'payment.method' => ['required_with:payment', Rule::in(PaymentMethods::names())],
            'payment.reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
