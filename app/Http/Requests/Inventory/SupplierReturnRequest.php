<?php

namespace App\Http\Requests\Inventory;

use App\Models\SupplierReturn;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', SupplierReturn::class);
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class)->id();

        return [
            'batch_id' => ['required', Rule::exists('medicine_batches', 'id')->where('tenant_id', $tenant)],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('tenant_id', $tenant)],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', Rule::in(SupplierReturn::REASONS)],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
