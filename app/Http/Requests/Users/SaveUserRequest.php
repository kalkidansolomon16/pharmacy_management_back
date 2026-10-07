<?php

namespace App\Http\Requests\Users;

use App\Models\Tenant;
use App\Models\User;
use App\Rules\EthiopianPhone;
use App\Rules\UniquePhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Create / update a team member. The roles offered depend on who is asking:
 * tenant admins may only grant roles of their own tenant type.
 */
class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target ? $this->user()->can('update', $target) : $this->user()->can('create', \App\Models\User::class);
    }

    public function rules(): array
    {
        $target = $this->route('user');
        $creating = ! $target;

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')->ignore($target?->id)],
            'phone' => ['nullable', new EthiopianPhone, new UniquePhone($target?->id)],
            'role' => ['required', Rule::in($this->assignableRoles())],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended'])],
            'license_number' => ['nullable', 'string', 'max:60'],
            'tenant_id' => [Rule::requiredIf(fn () => $this->user()->isSuperAdmin() && $this->input('role') !== User::ROLE_SUPER_ADMIN), 'nullable', 'exists:tenants,id'],
            'password' => [$creating ? 'required' : 'nullable', 'string', Password::min(8)->letters()->mixedCase()->numbers()],
        ];
    }

    public function assignableRoles(): array
    {
        $actor = $this->user();

        if ($actor->isSuperAdmin()) {
            $tenantType = Tenant::find($this->input('tenant_id'))?->type;

            return $tenantType ? User::TENANT_ROLES[$tenantType] : [User::ROLE_SUPER_ADMIN];
        }

        return User::TENANT_ROLES[$actor->tenant?->type] ?? [];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }
}
