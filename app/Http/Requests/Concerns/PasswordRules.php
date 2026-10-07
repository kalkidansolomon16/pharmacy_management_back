<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rules\Password;

trait PasswordRules
{
    protected function passwordRules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'confirmed',
            Password::min(8)->letters()->mixedCase()->numbers(),
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }
}
