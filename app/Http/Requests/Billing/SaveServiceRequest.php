<?php

namespace App\Http\Requests\Billing;

use App\Models\Service;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $service = $this->route('service');

        return $service ? $this->user()->can('update', $service) : $this->user()->can('create', Service::class);
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('services', 'code')->where('tenant_id', app(TenantContext::class)->id())->ignore($this->route('service')?->id)],
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(Service::CATEGORIES)],
            'price' => ['required', 'numeric', 'min:0'],
            'tax_category' => ['required', Rule::in(['exempt', 'standard'])],
            'is_active' => ['boolean'],
        ];
    }
}
