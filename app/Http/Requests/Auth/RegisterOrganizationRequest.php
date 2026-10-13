<?php

namespace App\Http\Requests\Auth;

use App\Models\Tenant;
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
            'organization.email' => ['nullable', 'email', 'max:191', Tenant::uniqueEmailRule()],
            'organization.region' => ['required', 'string', 'max:80'],
            'organization.city' => ['required', 'string', 'max:80'],
            'organization.sub_city' => ['nullable', 'string', 'max:80'],
            'organization.woreda' => ['nullable', 'string', 'max:40'],
            'organization.address_line' => ['nullable', 'string', 'max:191'],
            // Map pin (optional at registration; can be set later from the Organization page)
            'organization.latitude' => ['nullable', 'required_with:organization.longitude', 'numeric', 'between:3,15'],   // Ethiopia's latitude band
            'organization.longitude' => ['nullable', 'required_with:organization.latitude', 'numeric', 'between:33,48'], // Ethiopia's longitude band
            'organization.opening_hours' => ['nullable', 'string', 'max:100'],
            'organization.is_24_hours' => ['boolean'],
            'organization.delivery_available' => ['boolean'],
            // Hospitals & clinics: the specialties they offer (optional - can be added later on the Organization page)
            'organization.specialty_ids' => ['exclude_unless:organization.type,hospital', 'nullable', 'array', 'max:40'],
            'organization.specialty_ids.*' => ['integer', 'distinct', Rule::exists('hospital_specialties', 'id')->where('is_active', true)],
            // Hospitals & clinics with their own pharmacy register it at the same time (own EFDA licence + pharmacist in charge)
            'has_pharmacy' => ['boolean'],
            'pharmacy' => ['exclude_unless:has_pharmacy,true', 'required', 'array'],
            'pharmacy.name' => ['exclude_unless:has_pharmacy,true', 'required', 'string', 'max:150'],
            'pharmacy.license_number' => ['exclude_unless:has_pharmacy,true', 'required', 'string', 'max:60', 'different:organization.license_number'],
            'pharmacy.phone' => ['exclude_unless:has_pharmacy,true', 'nullable', EthiopianPhone::orLandline()],
            'pharmacy.email' => ['exclude_unless:has_pharmacy,true', 'nullable', 'email', 'max:191', Tenant::uniqueEmailRule(), 'different:organization.email'],
            'pharmacy.opening_hours' => ['exclude_unless:has_pharmacy,true', 'nullable', 'string', 'max:100'],
            'pharmacy.is_24_hours' => ['exclude_unless:has_pharmacy,true', 'boolean'],
            'pharmacy.delivery_available' => ['exclude_unless:has_pharmacy,true', 'boolean'],
            'pharmacy.admin.name' => ['exclude_unless:has_pharmacy,true', 'required', 'string', 'max:120'],
            'pharmacy.admin.email' => ['exclude_unless:has_pharmacy,true', 'required', 'email', 'max:191', 'unique:users,email', 'different:admin.email'],
            'pharmacy.admin.phone' => ['exclude_unless:has_pharmacy,true', 'required', new EthiopianPhone, new UniquePhone],
            'pharmacy.admin.password' => ['exclude_unless:has_pharmacy,true', 'required', 'string', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()],
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
            'pharmacy.name' => 'pharmacy name',
            'pharmacy.license_number' => 'pharmacy EFDA licence number',
            'pharmacy.phone' => 'pharmacy phone',
            'pharmacy.email' => 'pharmacy email',
            'pharmacy.admin.name' => "pharmacist's full name",
            'pharmacy.admin.email' => "pharmacist's email",
            'pharmacy.admin.phone' => "pharmacist's mobile",
            'pharmacy.admin.password' => 'pharmacy password',
        ];
    }

    public function messages(): array
    {
        return [
            'organization.email.unique' => 'Another organization already uses this email. Use your own organization email.',
            'organization.latitude.between' => 'The map pin must be inside Ethiopia.',
            'organization.longitude.between' => 'The map pin must be inside Ethiopia.',
            'pharmacy.email.unique' => 'Another organization already uses this email.',
            'pharmacy.email.different' => 'Use a different email from the hospital, or leave it empty.',
            'pharmacy.admin.email.different' => 'The pharmacy needs its own login - use a different email from the hospital administrator.',
            'pharmacy.admin.email.unique' => 'This email already has a MedLink account.',
            'pharmacy.license_number.different' => 'The pharmacy has its own EFDA licence - enter that number, not the hospital licence.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $org = $this->input('organization', []);
        if (is_array($org) && ! empty($org['email']) && is_string($org['email'])) {
            $org['email'] = strtolower(trim($org['email']));
            $this->merge(['organization' => $org]);
        }

        // Only hospitals & clinics register an in-house pharmacy
        if (($org['type'] ?? null) !== 'hospital') {
            $this->merge(['has_pharmacy' => false]);
        }
        $pharmacy = $this->input('pharmacy');
        if (is_array($pharmacy)) {
            if (! empty($pharmacy['email']) && is_string($pharmacy['email'])) {
                $pharmacy['email'] = strtolower(trim($pharmacy['email']));
            }
            if (! empty($pharmacy['admin']['email']) && is_string($pharmacy['admin']['email'])) {
                $pharmacy['admin']['email'] = strtolower(trim($pharmacy['admin']['email']));
            }
            $this->merge(['pharmacy' => $pharmacy]);
        }

        $admin = $this->input('admin', []);
        if (isset($admin['email'])) {
            $admin['email'] = strtolower(trim($admin['email']));
            $this->merge(['admin' => $admin]);
        }
    }
}
