<?php

namespace App\Http\Requests\Inventory;

use App\Models\PurchaseOrder;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', PurchaseOrder::class);
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class)->id();

        return [
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('tenant_id', $tenant)],
            'store_id' => ['nullable', Rule::exists('stores', 'id')->where('tenant_id', $tenant)],
            'expected_at' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.pharmacy_medicine_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ];
    }
}
