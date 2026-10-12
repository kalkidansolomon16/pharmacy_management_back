<?php

namespace App\Http\Requests\Billing;

use App\Models\Invoice;
use App\Rules\EthiopianPhone;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('billing.collect') || $this->user()->can('create', Invoice::class);
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class)->id();

        return [
            'patient_id' => ['nullable', Rule::exists('patients', 'id')->where('tenant_id', $tenant)],
            'visit_id' => ['nullable', Rule::exists('visits', 'id')->where('tenant_id', $tenant)],
            'customer_name' => ['required_without:patient_id', 'nullable', 'string', 'max:150'],
            'customer_phone' => ['nullable', new EthiopianPhone],
            'customer_tin' => ['nullable', 'digits:10'],
            'membership_id' => ['nullable', 'integer'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.service_id' => ['nullable', Rule::exists('services', 'id')->where('tenant_id', $tenant)],
            'lines.*.description' => ['required_without:lines.*.service_id', 'nullable', 'string', 'max:191'],
            'lines.*.item_type' => ['nullable', Rule::in(['registration', 'consultation', 'procedure', 'other'])],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:10000'],
            'lines.*.unit_price' => ['required_without:lines.*.service_id', 'nullable', 'numeric', 'min:0'],
            'lines.*.tax_category' => ['nullable', Rule::in(['exempt', 'standard'])],
        ];
    }
}
