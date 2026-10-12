<?php

namespace App\Http\Requests\Billing;

use App\Models\Payer;
use App\Rules\EthiopianPhone;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payer = $this->route('payer');

        return $payer ? $this->user()->can('update', $payer) : $this->user()->can('create', Payer::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:20', Rule::unique('payers', 'code')->where('tenant_id', app(TenantContext::class)->id())->ignore($this->route('payer')?->id)],
            'type' => ['required', Rule::in(Payer::TYPES)],
            'tin_number' => ['nullable', 'digits:10'],
            'phone' => ['nullable', EthiopianPhone::orLandline()],
            'email' => ['nullable', 'email'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:191'],
            'default_copay_percent' => ['required', 'numeric', 'between:0,100'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'payment_terms_days' => ['required', 'integer', 'between:0,365'],
            'preauth_threshold' => ['nullable', 'numeric', 'min:0'],
            'coverage' => ['nullable', 'array'],
            'coverage.*' => ['boolean'],
            'excluded_category_ids' => ['nullable', 'array'],
            'excluded_category_ids.*' => ['integer', 'exists:medicine_categories,id'],
            'is_active' => ['boolean'],
        ];
    }
}
