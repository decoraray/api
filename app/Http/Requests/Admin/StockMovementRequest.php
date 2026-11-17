<?php

namespace App\Http\Requests\Admin;

use App\Models\StockMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockMovementRequest extends FormRequest
{
    /** A unit cost may only come from someone allowed to see costs; refuse loudly rather than drop it. */
    public function authorize()
    {
        return $this->input('unit_cost') === null || (bool) $this->user()?->can('costs.view');
    }

    public function rules()
    {
        $isCount = $this->input('type') === StockMovement::TYPE_ADJUST;
        $isReceipt = $this->input('type') === StockMovement::TYPE_IN;

        return [
            'type' => ['required', Rule::in([StockMovement::TYPE_IN, StockMovement::TYPE_OUT, StockMovement::TYPE_ADJUST])],
            // For a count this is the counted quantity, which may be zero.
            'quantity' => ['required', 'numeric', $isCount ? 'min:0' : 'gt:0', 'max:999999999', 'regex:/^\d+(\.\d{1,3})?$/'],
            'unit_cost' => [$isReceipt ? 'nullable' : 'prohibited', 'integer', 'min:0'],
            'note' => [$isReceipt ? 'nullable' : 'required', 'string', 'max:500'],
        ];
    }

    public function messages()
    {
        return [
            'note.required' => 'برای خروج و شمارش، توضیح لازم است.',
            'quantity.regex' => 'مقدار حداکثر سه رقم اعشار دارد.',
        ];
    }
}
