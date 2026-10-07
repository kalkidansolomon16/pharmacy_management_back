<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\PasswordRules;
use App\Rules\EthiopianPhone;
use App\Rules\UniquePhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterCustomerRequest extends FormRequest
{
    use PasswordRules;

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'phone' => ['required', new EthiopianPhone, new UniquePhone],
            'city' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:191'],
            'locale' => ['nullable', Rule::in(['en', 'am'])],
            'password' => $this->passwordRules(),
        ];
    }
}
