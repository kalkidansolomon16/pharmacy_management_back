<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceiveBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageStock', $this->route('listing'));
    }

    public function rules(): array
    {
        return [
            'batch_number' => [
                'required', 'string', 'max:60',
                Rule::unique('medicine_batches')->where('pharmacy_medicine_id', $this->route('listing')->id),
            ],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'expiry_date' => ['required', 'date', 'after:today'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'supplier' => ['nullable', 'string', 'max:120'],
            'received_at' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'expiry_date.after' => 'Expired or same-day-expiry stock cannot be received.',
            'batch_number.unique' => 'This batch number already exists for this medicine.',
        ];
    }
}
