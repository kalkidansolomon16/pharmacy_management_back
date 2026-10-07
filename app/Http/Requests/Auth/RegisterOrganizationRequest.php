<?php

namespace App\Http\Requests\Auth;

use App\Rules\EthiopianPhone;
use App\Rules\UniquePhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterOrganizationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Organization
            'organization.name' => ['required', 'string', 'max:150'],
            'organization.type' => ['required', Rule::in(['pharmacy', 'hospital'])],
            'organization.license_number' => ['required', 'string', 'max:60'],
            'organization.tin_number' => ['nullable', 'digits:10'],
            'organization.phone' => ['required', EthiopianPhone::orLandline()],
            'organization.email' => ['nullable', 'email'],
            'organization.region' => ['required', 'string', 'max:80'],
            'organization.city' => ['required', 'string', 'max:80'],
            'organization.sub_city' => ['nullable', 'string', 'max:80'],
            'organization.woreda' => ['nullable', 'string', 'max:40'],
            'organization.address_line' => ['nullable', 'string', 'max:191'],
            'organization.opening_hours' => ['nullable', 'string', 'max:100'],
            'organization.is_24_hours' => ['boolean'],
            'organization.delivery_available' => ['boolean'],
            // First administrator account
            'admin.name' => ['required', 'string', 'max:120'],
            'admin.email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'admin.phone' => ['required', new EthiopianPhone, new UniquePhone],
            'admin.password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()],
        ];
    }

    public function attributes(): array
    {
        return [
            'organization.name' => 'organization name',
            'organization.license_number' => 'EFDA licence number',
            'organization.tin_number' => 'TIN',
            'organization.phone' => 'organization phone',
            'admin.name' => 'full name',
            'admin.email' => 'email',
            'admin.phone' => 'phone',
            'admin.password' => 'password',
        ];
    }

    protected function prepareForValidation(): void
    {
        $admin = $this->input('admin', []);
        if (isset($admin['email'])) {
            $admin['email'] = strtolower(trim($admin['email']));
            $this->merge(['admin' => $admin]);
        }
    }
}
