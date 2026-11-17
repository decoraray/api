<?php

namespace App\Http\Requests\Admin;

use App\Enums\ProductionStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveWorkOrderRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'stage' => ['required', 'string', Rule::in(ProductionStage::names())],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
