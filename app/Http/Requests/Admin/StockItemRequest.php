<?php

namespace App\Http\Requests\Admin;

use App\Enums\StockItemKind;
use App\Enums\StockUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockItemRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $id = $this->route('stockItem')?->id;
        $isMdf = $this->input('kind') === StockItemKind::MDF_SHEET->value;
        $quantity = ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'];

        return [
            'code' => ['required', 'string', 'max:60', Rule::unique('stock_items', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:190'],
            'kind' => ['required', Rule::in(array_column(StockItemKind::cases(), 'value'))],
            'unit' => ['required', Rule::in(array_column(StockUnit::cases(), 'value'))],
            'thickness_mm' => [$isMdf ? 'required' : 'nullable', 'numeric', 'gt:0', 'max:100'],
            'color' => ['nullable', 'string', 'max:100'],
            'length_mm' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'width_mm' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'sheet_type' => ['nullable', 'string', 'max:100'],
            // MDF sheets: cutting allowance and the band their banded edges use.
            'waste_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'default_edge_band_id' => ['nullable', 'integer', Rule::exists('stock_items', 'id')->where('kind', StockItemKind::EDGE_BAND->value)],
            'reorder_point' => $quantity,
            'active' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages()
    {
        return [
            'code.unique' => 'این کد قبلاً ثبت شده است.',
            'thickness_mm.required' => 'ضخامت ورق MDF لازم است.',
            'waste_percent.*' => 'درصد پرت باید بین ۰ تا ۱۰۰ باشد.',
            'default_edge_band_id.exists' => 'نوار لبهٔ پیش‌فرض باید قلمی از نوع «نوار لبه» باشد.',
        ];
    }
}
