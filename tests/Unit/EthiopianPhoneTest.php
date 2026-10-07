<?php

namespace Tests\Unit;

use App\Rules\EthiopianPhone;
use App\Support\ReferenceGenerator;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EthiopianPhoneTest extends TestCase
{
    public static function numbers(): array
    {
        return [
            'ethio telecom local' => ['0911223344', true, '+251911223344'],
            'safaricom local' => ['0712345678', true, '+251712345678'],
            'international' => ['+251 911 223 344', true, '+251911223344'],
            'no plus' => ['251911223344', true, '+251911223344'],
            'nine digits' => ['911223344', true, '+251911223344'],
            'landline (mobile only)' => ['0115512345', false, '+251115512345'],
            'too short' => ['09112233', false, null],
            'foreign' => ['+14155552671', false, null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_mobile_validation_and_normalisation(string $input, bool $valid, ?string $normalised): void
    {
        $this->assertSame($valid, Validator::make(['p' => $input], ['p' => [new EthiopianPhone]])->passes());

        if ($normalised) {
            $this->assertSame($normalised, ReferenceGenerator::normalizePhone($input));
        }
    }

    public function test_organizations_may_use_landlines(): void
    {
        $this->assertTrue(Validator::make(['p' => '011 551 2345'], ['p' => [EthiopianPhone::orLandline()]])->passes());
    }

    public function test_prescription_codes_are_unambiguous(): void
    {
        $code = ReferenceGenerator::prescriptionCode();

        $this->assertMatchesRegularExpression('/^RX-[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}$/', $code);
    }
}
