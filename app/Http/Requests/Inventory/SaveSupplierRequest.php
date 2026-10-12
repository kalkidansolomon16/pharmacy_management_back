<?php

namespace App\Http\Requests\Inventory;

use App\Models\Supplier;
use App\Rules\EthiopianPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        $supplier = $this->route('supplier');

        return $supplier ? $this->user()->can('update', $supplier) : $this->user()->can('create', Supplier::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(Supplier::TYPES)],
            'tin_number' => ['nullable', 'digits:10'],
            'license_number' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', EthiopianPhone::orLandline()],
            'email' => ['nullable', 'email'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:191'],
            'payment_terms_days' => ['nullable', 'integer', 'between:0,365'],
            'is_active' => ['boolean'],
        ];
    }
}
