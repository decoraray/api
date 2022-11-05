<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;

class CartRequest extends FormRequest
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
     * Get the error messages for the defined validation rules.*
     * @return array
     */
    protected function failedValidation(Validator|\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'errors' => $validator->errors(),
            'status' => true
        ], 422));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'uuid' => 'nullable|string',
            'product' => 'required|integer',
            'quantity' => 'nullable|integer',
            'description' => 'nullable|string',
        ];
    }

    // get product
    public function getProduct() {
        return $this->input('product');
    }

    // get uuid
    public function getUuid(){
        return $this->input('uuid')??null;
    }

    // get description
    public function getDescription(){
        return $this->input('description')??null;
    }

    // get quantity
    public function getQuantity(){
        return $this->input('quantity')??1;
    }
}
