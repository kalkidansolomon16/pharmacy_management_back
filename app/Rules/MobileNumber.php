<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class MobileNumber implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        //
    }
      public function passes($attribute, $value)
    {
        $cleanedValue = preg_replace('/[^0-9]/', '', $value);

        if (preg_match('/^(09|07)\d{8}$/', $cleanedValue)) {
            return strlen($cleanedValue) === 10;
        } elseif (preg_match('/^251\d{9}$/', $cleanedValue)) {
            return strlen($cleanedValue) === 12;
        } elseif (preg_match('/^\+251\d{10}$/', $cleanedValue)) {
            return strlen($cleanedValue) === 13;
        }

        return false;
    }
       public function message()
    {
        return 'The :attribute must start with "09," "07," "251," or "+251" and have the correct total number of digits.';
    }
}
