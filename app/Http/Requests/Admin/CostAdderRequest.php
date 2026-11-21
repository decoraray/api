<?php

namespace App\Http\Requests\Admin;

use App\Enums\CostAdderBasis;
use App\Enums\CostAdderGroup;
use App\Enums\CostAdderUnit;
use App\Models\CostAdder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CostAdderRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $id = $this->route('costAdder')?->id;
        $isStock = $this->input('basis') === CostAdderBasis::STOCK->value;
        $isRail = $this->input('group') === CostAdderGroup::RAIL->value;

        return [
            'name' => ['required', 'string', 'max:190', Rule::unique('cost_adders', 'name')->ignore($id)],
            'group' => ['required', Rule::in(array_column(CostAdderGroup::cases(), 'value'))],
            'basis' => ['required', Rule::in($isRail ? [CostAdderBasis::STOCK->value] : array_column(CostAdderBasis::cases(), 'value'))],
            'stock_item_id' => $isStock
                ? ['required', 'integer', Rule::exists('stock_items', 'id')->where('active', true)]
                : ['prohibited'],
            'unit' => $isStock ? ['prohibited'] : ['required', Rule::in(array_column(CostAdderUnit::cases(), 'value'))],
            'unit_cost' => $isStock ? ['prohibited'] : ['required', 'integer', 'min:0', 'max:999999999999'],
            'length_cm' => [$isRail ? 'required' : 'nullable', 'integer', 'min:1', 'max:1000'],
            'active' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || $this->input('group') !== CostAdderGroup::RAIL->value) {
                return;
            }
            $active = $this->has('active') ? $this->boolean('active') : ($this->route('costAdder')?->active ?? true);
            if (!$active) {
                return;
            }
            $taken = CostAdder::where('group', CostAdderGroup::RAIL->value)
                ->where('stock_item_id', $this->input('stock_item_id'))
                ->where('active', true)
                ->when($this->route('costAdder'), fn ($q, $adder) => $q->whereKeyNot($adder->id))
                ->exists();
            if ($taken) {
                $validator->errors()->add('stock_item_id', 'برای این قلم انبار ریل فعال دیگری تعریف شده است.');
            }
        }];
    }

    /** Fields the other basis does not use are cleared, so switching basis leaves nothing stale. */
    public function adderAttributes(): array
    {
        $data = $this->validated();
        $isStock = $data['basis'] === CostAdderBasis::STOCK->value;

        return array_merge($data, [
            'stock_item_id' => $isStock ? $data['stock_item_id'] : null,
            'unit' => $isStock ? null : $data['unit'],
            'unit_cost' => $isStock ? null : (int) $data['unit_cost'],
            'length_cm' => $data['length_cm'] ?? null,
        ]);
    }

    public function messages()
    {
        return [
            'name.unique' => 'افزودنی با این نام قبلاً ثبت شده است.',
            'basis.in' => 'ریل باید به یک قلم انبار وصل باشد.',
            'stock_item_id.required' => 'قلم انبار را انتخاب کنید.',
            'stock_item_id.exists' => 'قلم انبار باید فعال باشد.',
            'stock_item_id.prohibited' => 'افزودنی خدماتی به قلم انبار وصل نمی‌شود.',
            'unit.required' => 'واحد را انتخاب کنید.',
            'unit.prohibited' => 'افزودنی انباری واحد جداگانه ندارد؛ واحد قلم انبار به کار می‌رود.',
            'unit_cost.required' => 'هزینه واحد را وارد کنید.',
            'unit_cost.prohibited' => 'هزینه افزودنی انباری از میانگین بهای انبار می‌آید.',
            'length_cm.required' => 'طول ریل را به سانتی‌متر وارد کنید.',
        ];
    }
}
