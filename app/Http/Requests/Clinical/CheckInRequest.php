<?php

namespace App\Http\Requests\Clinical;

use App\Models\Visit;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Visit::class);
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class)->id();

        return [
            'patient_id' => ['required', Rule::exists('patients', 'id')->where('tenant_id', $tenant)],
            'type' => ['required', Rule::in(Visit::TYPES)],
            'department' => ['nullable', 'string', 'max:80'],
            'priority' => ['nullable', Rule::in(['normal', 'urgent'])],
            'doctor_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenant)],
            'membership_id' => ['nullable', 'integer'],
            'chief_complaint' => ['nullable', 'string', 'max:500'],
        ];
    }
}
