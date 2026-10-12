<?php

namespace App\Http\Requests\Clinical;

use App\Models\LabOrder;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LabOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', LabOrder::class);
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class)->id();

        return [
            'patient_id' => ['required', Rule::exists('patients', 'id')->where('tenant_id', $tenant)],
            'visit_id' => ['nullable', Rule::exists('visits', 'id')->where('tenant_id', $tenant)],
            'tests' => ['required', 'array', 'min:1', 'max:30'],
            'tests.*' => ['integer', 'distinct'],
            'priority' => ['nullable', Rule::in(['routine', 'urgent'])],
            'clinical_notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
