<?php

namespace App\Http\Requests\Billing;

use App\Models\PayerMembership;
use App\Rules\EthiopianPhone;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        $membership = $this->route('membership');

        return $membership ? $this->user()->can('update', $membership) : $this->user()->can('create', PayerMembership::class);
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class)->id();

        return [
            'payer_id' => ['required', Rule::exists('payers', 'id')->where('tenant_id', $tenant)],
            'patient_id' => ['nullable', Rule::exists('patients', 'id')->where('tenant_id', $tenant)],
            'member_number' => ['required', 'string', 'max:40', Rule::unique('payer_memberships', 'member_number')->where('payer_id', $this->input('payer_id'))->ignore($this->route('membership')?->id)],
            'beneficiary_name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', new EthiopianPhone],
            'scheme' => ['nullable', 'string', 'max:120'],
            'employer' => ['nullable', 'string', 'max:150'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'copay_percent' => ['nullable', 'numeric', 'between:0,100'],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'expired'])],
        ];
    }
}
