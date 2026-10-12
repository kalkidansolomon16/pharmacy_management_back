<?php

namespace App\Http\Requests\Inventory;

use App\Models\Store;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $store = $this->route('store');

        return $store ? $this->user()->can('update', $store) : $this->user()->can('create', Store::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:20', Rule::unique('stores', 'code')->where('tenant_id', app(TenantContext::class)->id())->ignore($this->route('store')?->id)],
            'type' => ['required', Rule::in(Store::TYPES)],
            'is_dispensing' => ['boolean'],
            'is_default' => ['boolean'],
        ];
    }
}
