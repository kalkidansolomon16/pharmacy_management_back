<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Ethiopian phone numbers: Ethio telecom (09…) and Safaricom (07…) mobiles, written locally
 * (0911223344) or internationally (+251911223344). Organizations may also use landlines (011…, 046…).
 */
class EthiopianPhone implements ValidationRule
{
    public function __construct(private bool $allowLandline = false) {}

    public static function orLandline(): self
    {
        return new self(true);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = preg_replace('/[\s\-()]/', '', (string) $value);
        $prefixes = $this->allowLandline ? '[1-579]' : '[79]';

        if (! preg_match('/^(?:\+?251|0)?'.$prefixes.'\d{8}$/', $digits)) {
            $fail($this->allowLandline
                ? 'The :attribute must be a valid Ethiopian phone number, e.g. 0911 223 344 or 011 551 2345.'
                : 'The :attribute must be a valid Ethiopian mobile number, e.g. 0911 223 344 or +251 911 223 344.');
        }
    }
}
