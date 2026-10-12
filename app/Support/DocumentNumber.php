<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Gapless, per-organization document numbers per Ethiopian fiscal year, e.g. INV-2019-000042.
 * Fiscal law requires invoice numbers without gaps, so numbers come from a locked counter row,
 * not from auto-increment ids. Call inside the transaction that creates the document.
 */
final class DocumentNumber
{
    public const PREFIXES = [
        'invoice' => 'INV',
        'credit_note' => 'CRN',
        'visit' => 'V',
        'lab' => 'LAB',
        'claim' => 'CLM',
        'purchase_order' => 'PO',
        'transfer' => 'TRF',
        'supplier_return' => 'RTN',
    ];

    public static function next(int $tenantId, string $type, ?int $year = null): string
    {
        $year ??= EthiopianCalendar::fiscalYear();

        $number = DB::transaction(function () use ($tenantId, $type, $year) {
            $row = DB::table('document_sequences')
                ->where(['tenant_id' => $tenantId, 'type' => $type, 'year' => $year])
                ->lockForUpdate()
                ->first();

            if (! $row) {
                DB::table('document_sequences')->insert(['tenant_id' => $tenantId, 'type' => $type, 'year' => $year, 'next_number' => 2]);

                return 1;
            }

            DB::table('document_sequences')->where('id', $row->id)->update(['next_number' => $row->next_number + 1]);

            return $row->next_number;
        });

        $prefix = self::PREFIXES[$type] ?? strtoupper($type);
        $width = in_array($type, ['invoice', 'credit_note'], true) ? 6 : 5;

        return sprintf('%s-%d-%0'.$width.'d', $prefix, $year, $number);
    }
}
