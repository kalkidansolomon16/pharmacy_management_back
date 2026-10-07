<?php

namespace App\Http\Requests\Orders;

use App\Models\Order;
use App\Rules\EthiopianPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WalkInSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sell', \App\Models\Order::class);
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.pharmacy_medicine_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'prescription_code' => ['nullable', 'string', 'max:20'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', new EthiopianPhone],
            'payment_method' => ['required', Rule::in(Order::PAYMENT_METHODS)],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'items.*.quantity' => 'quantity',
            'items.*.pharmacy_medicine_id' => 'medicine',
        ];
    }
}
