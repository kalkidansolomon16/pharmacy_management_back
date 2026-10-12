<?php

namespace App\Http\Requests\Inventory;

use App\Models\StockTransfer;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', StockTransfer::class);
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class)->id();

        return [
            'from_store_id' => ['required', Rule::exists('stores', 'id')->where('tenant_id', $tenant)],
            'to_store_id' => ['required', 'different:from_store_id', Rule::exists('stores', 'id')->where('tenant_id', $tenant)],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.pharmacy_medicine_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
