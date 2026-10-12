<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Support\EthiopianCalendar;
use App\Support\Gs1Parser;
use App\Support\TaxCalculator;
use Carbon\Carbon;
use Tests\TestCase;

class EthiopiaSupportTest extends TestCase
{
    public function test_gregorian_dates_convert_to_the_ethiopian_calendar(): void
    {
        $this->assertSame(['year' => 2019, 'month' => 1, 'day' => 1], EthiopianCalendar::fromGregorian(Carbon::parse('2026-09-11')));
        $this->assertSame(['year' => 2016, 'month' => 13, 'day' => 5], EthiopianCalendar::fromGregorian(Carbon::parse('2024-09-10')));
        $this->assertSame(['year' => 2017, 'month' => 4, 'day' => 29], EthiopianCalendar::fromGregorian(Carbon::parse('2025-01-07')));
        $this->assertSame('Meskerem 1, 2019 E.C.', EthiopianCalendar::format(Carbon::parse('2026-09-11')));
    }

    public function test_the_fiscal_year_starts_on_hamle_1(): void
    {
        $this->assertSame(2018, EthiopianCalendar::fiscalYear(Carbon::parse('2026-07-07'))); // Sene 30, 2018
        $this->assertSame(2019, EthiopianCalendar::fiscalYear(Carbon::parse('2026-07-08'))); // Hamle 1, 2018
        $this->assertSame(2019, EthiopianCalendar::fiscalYear(Carbon::parse('2026-10-09')));
    }

    public function test_tax_depends_on_the_sellers_regime_and_item_category(): void
    {
        $vat = new Tenant(['tax_regime' => 'vat']);
        $tot = new Tenant(['tax_regime' => 'tot']);
        $exempt = new Tenant(['tax_regime' => 'exempt']);

        $this->assertSame(['rate' => 0.15, 'tax' => 15.0, 'total' => 115.0], TaxCalculator::line($vat, 100, 'standard'));
        $this->assertSame(0.0, TaxCalculator::rate($vat, 'exempt'));
        $this->assertSame(0.02, TaxCalculator::rate($tot, 'standard'));
        $this->assertSame(0.1, TaxCalculator::rate($tot, 'standard', isService: true));
        $this->assertSame(0.0, TaxCalculator::rate($exempt, 'standard'));
    }

    public function test_gs1_datamatrix_payloads_are_parsed(): void
    {
        $gs = "\x1D";
        $parsed = Gs1Parser::parse(']d2010622300001234517281130'.'10AMX2401'.$gs.'21SN998877');

        $this->assertSame('06223000012345', $parsed['gtin']);
        $this->assertSame('2028-11-30', $parsed['expiry']);
        $this->assertSame('AMX2401', $parsed['batch']);
        $this->assertSame('SN998877', $parsed['serial']);

        // Day "00" means the end of that month
        $this->assertSame('2027-02-28', Gs1Parser::parse('010622300001234517270200')['expiry']);
        $this->assertNull(Gs1Parser::parse('hello world'));
    }

    public function test_lab_reference_ranges_keep_significant_zeros(): void
    {
        $test = new \App\Models\LabTest(['reference_low' => '70.00', 'reference_high' => '110.00', 'unit' => 'mg/dL']);
        $this->assertSame('70 - 110 mg/dL', $test->referenceLabel());
        $this->assertSame('0.6 - 1.3', (new \App\Models\LabTest(['reference_low' => '0.60', 'reference_high' => '1.30']))->referenceLabel());
    }
}
