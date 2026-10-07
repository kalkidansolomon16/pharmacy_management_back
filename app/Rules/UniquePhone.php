<?php

namespace App\Rules;

use App\Models\User;
use App\Support\ReferenceGenerator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Phone numbers are stored normalised (+2519...), so uniqueness must compare normalised values.
 */
class UniquePhone implements ValidationRule
{
    public function __construct(private ?int $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = User::where('phone', ReferenceGenerator::normalizePhone($value))
            ->when($this->ignoreUserId, fn ($q) => $q->whereKeyNot($this->ignoreUserId))
            ->exists();

        if ($exists) {
            $fail('This phone number is already registered.');
        }
    }
}
