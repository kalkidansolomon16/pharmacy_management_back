<?php

namespace App\Http\Requests\Hospital;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePrescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Prescription::class);
    }

    public function rules(): array
    {
        return [
            // The patient must belong to the doctor's own hospital
            'patient_id' => ['required', Rule::exists('patients', 'id')->where('tenant_id', app(TenantContext::class)->id())],
            'diagnosis' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:180'],
            'items' => ['required', 'array', 'min:1', 'max:15'],
            'items.*.medicine_id' => ['required', 'integer', 'distinct', 'exists:medicines,id'],
            'items.*.dosage' => ['required', 'string', 'max:60'],
            'items.*.frequency' => ['required', 'string', 'max:60'],
            'items.*.duration_days' => ['required', 'integer', 'min:1', 'max:365'],
            'items.*.total_quantity' => ['required', 'integer', 'min:1', 'max:5000'],
            'items.*.instructions' => ['nullable', 'string', 'max:191'],
        ];
    }

    public function messages(): array
    {
        return ['items.*.medicine_id.distinct' => 'Each medicine may appear only once per prescription.'];
    }
}
