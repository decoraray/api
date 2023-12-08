<?php

namespace App\Http\Requests;

use App\Models\City;
use Illuminate\Foundation\Http\FormRequest;

class AddressCreateRequest extends FormRequest
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
            'title' => 'nullable|string|max:255',
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'postal_code' => 'nullable|string|max:255',
            'city_id' => 'nullable|integer|exists:cities,id|gt:31', // 31 is the biggest county id
        ];
    }

    // get title from request or set a default value
    public function title()
    {
        return $this->title ?? 'آدرس ' . auth()->user()->addresses()->count() + 1 . ' '. $this->city();
    }

    public function city()
    {
        return City::find($data['city_id'])->title;
    }

    public function county()
    {
        return City::find($data['city_id'])->parent()->first()->title;
    }
}
