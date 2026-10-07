<?php

namespace App\Http\Requests\Hospital;

use App\Rules\EthiopianPhone;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $patient = $this->route('patient');

        return $patient ? $this->user()->can('update', $patient) : $this->user()->can('create', \App\Models\Patient::class);
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', new EthiopianPhone],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'mrn' => [
                'nullable', 'string', 'max:40',
                Rule::unique('patients', 'mrn')->where('tenant_id', app(TenantContext::class)->id())->ignore($this->route('patient')?->id),
            ],
            'city' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:191'],
            'blood_group' => ['nullable', Rule::in(config('ethiopia.blood_groups'))],
            'allergies' => ['nullable', 'string', 'max:191'],
        ];
    }

    public function attributes(): array
    {
        return ['mrn' => 'card number (MRN)'];
    }
}
