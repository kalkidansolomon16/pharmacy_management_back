<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Resources\PharmacyMedicineResource;
use App\Models\MedicineBatch;
use App\Models\PharmacyMedicine;
use App\Support\Gs1Parser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Barcode scan: GS1 DataMatrix (GTIN + batch + expiry + serial) or a plain EAN/GTIN.
 */
class ScanController extends Controller
{
    public function __invoke(Request $request)
    {
        Gate::authorize('scan.use');
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:200']])['code'];

        $gs1 = Gs1Parser::parse($code);
        $gtin = $gs1['gtin'] ?? preg_replace('/\D/', '', $code);

        $listing = PharmacyMedicine::with('medicine')->withAvailableQuantity()
            ->whereHas('medicine', fn ($m) => $m->whereNotNull('barcode')->where(fn ($w) => $w->where('barcode', $gtin)->orWhere('barcode', ltrim($gtin, '0'))->orWhere('barcode', '0'.$gtin)))
            ->first();

        $batch = $listing && ($gs1['batch'] ?? null)
            ? MedicineBatch::where('pharmacy_medicine_id', $listing->id)->where('batch_number', $gs1['batch'])->first()
            : null;

        $warnings = [];
        if ($gs1['expiry'] ?? null) {
            if ($gs1['expiry'] <= today()->toDateString()) {
                $warnings[] = 'This pack is EXPIRED ('.$gs1['expiry'].'). Do not dispense.';
            } elseif ($gs1['expiry'] <= today()->addDays(90)->toDateString()) {
                $warnings[] = 'This pack expires soon ('.$gs1['expiry'].').';
            }
        }
        if ($gs1 && ! $batch && $listing) {
            $warnings[] = 'Batch '.($gs1['batch'] ?? '?').' is not recorded in your stock - receive it first.';
        }

        return $this->respond([
            'gs1' => $gs1,
            'gtin' => $gtin ?: null,
            'listing' => $listing ? (new PharmacyMedicineResource($listing))->resolve() : null,
            'batch' => $batch ? ['id' => $batch->id, 'batch_number' => $batch->batch_number, 'quantity' => $batch->quantity, 'expiry_date' => $batch->expiry_date->toDateString()] : null,
            'warnings' => $warnings,
        ]);
    }
}
