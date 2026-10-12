<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveGoodsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('purchaseOrder'));
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.batch_number' => ['required', 'string', 'max:60'],
            'lines.*.expiry_date' => ['required', 'date', 'after:today'],
            'lines.*.gtin' => ['nullable', 'string', 'max:20'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return ['lines.*.expiry_date.after' => 'Expired or same-day-expiry stock cannot be received.'];
    }
}
