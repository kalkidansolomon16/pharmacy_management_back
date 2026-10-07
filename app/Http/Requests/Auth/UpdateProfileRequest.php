<?php

namespace App\Http\Requests\Auth;

use App\Rules\EthiopianPhone;
use App\Rules\UniquePhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->user()->id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')->ignore($id)],
            'phone' => ['nullable', new EthiopianPhone, new UniquePhone($id)],
            'city' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:191'],
            'locale' => ['nullable', Rule::in(['en', 'am'])],
        ];
    }
}
