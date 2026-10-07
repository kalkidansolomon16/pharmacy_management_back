<?php

namespace App\Http\Requests\Orders;

use App\Models\Order;
use App\Rules\EthiopianPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('place', \App\Models\Order::class);
    }

    public function rules(): array
    {
        return [
            'pharmacy_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.pharmacy_medicine_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'prescription_code' => ['nullable', 'string', 'max:20'],
            // Proves the customer is the patient (or their carer) when a prescription is used
            'patient_phone' => ['required_with:prescription_code', 'nullable', new EthiopianPhone],
            'customer_phone' => ['nullable', new EthiopianPhone],
            'fulfillment' => ['required', Rule::in(['pickup', 'delivery'])],
            'delivery_address' => ['required_if:fulfillment,delivery', 'nullable', 'string', 'max:191'],
            'payment_method' => ['required', Rule::in(Order::PAYMENT_METHODS)],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'items.*.quantity' => 'quantity',
            'items.*.pharmacy_medicine_id' => 'medicine',
            'patient_phone' => "patient's phone",
        ];
    }
}
