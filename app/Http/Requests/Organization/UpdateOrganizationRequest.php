<?php

namespace App\Http\Requests\Organization;

use App\Rules\EthiopianPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    public function rules(): array
    {
        $moderating = $this->user()->can('tenants.manage');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'license_number' => ['sometimes', 'required', 'string', 'max:60'],
            'tin_number' => ['nullable', 'digits:10'],
            'phone' => ['sometimes', 'required', EthiopianPhone::orLandline()],
            'email' => ['nullable', 'email'],
            'region' => ['sometimes', 'required', 'string', 'max:80'],
            'city' => ['sometimes', 'required', 'string', 'max:80'],
            'sub_city' => ['nullable', 'string', 'max:80'],
            'woreda' => ['nullable', 'string', 'max:40'],
            'address_line' => ['nullable', 'string', 'max:191'],
            'latitude' => ['nullable', 'numeric', 'between:3,15'],   // Ethiopia's latitude band
            'longitude' => ['nullable', 'numeric', 'between:33,48'], // Ethiopia's longitude band
            'opening_hours' => ['nullable', 'string', 'max:100'],
            'is_24_hours' => ['boolean'],
            'delivery_available' => ['boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
            // Only the platform team can change verification status
            'status' => [$moderating ? 'sometimes' : 'prohibited', Rule::in(['pending', 'active', 'suspended', 'rejected'])],
        ];
    }
}
