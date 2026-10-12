<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Resources\PharmacyMedicineResource;
use App\Models\PharmacyMedicine;
use App\Services\Inventory\ControlledRegisterService;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportService;
use Illuminate\Http\Request;

/**
 * Narcotic & psychotropic register.
 */
class ControlledRegisterController extends Controller
{
    public function __construct(private ControlledRegisterService $register) {}

    public function index(Request $request)
    {
        $this->authorize('controlled.view');

        return PharmacyMedicineResource::collection($this->register->controlledListings());
    }

    public function show(Request $request, PharmacyMedicine $listing, ReportExporter $exporter)
    {
        $this->authorize('controlled.view');
        abort_unless($listing->medicine->is_controlled, 404);
        [$from, $to] = ReportService::range($request->query('from'), $request->query('to'), 90);
        $ledger = $this->register->ledger($listing, $from, $to);

        if ($format = $request->query('format')) {
            return $exporter->export($format === 'pdf' ? 'pdf' : 'csv', "Controlled drug register - {$ledger['medicine']}", "{$ledger['from']} to {$ledger['to']}", [
                'date' => 'Date', 'type' => 'Type', 'received' => 'In', 'issued' => 'Out', 'balance' => 'Balance', 'batch' => 'Batch',
                'prescription' => 'Rx', 'patient' => 'Patient', 'prescriber' => 'Prescriber', 'recorded_by' => 'By',
            ], collect($ledger['entries'])->map(fn ($e) => ['date' => substr($e['date'], 0, 16)] + $e), [
                'Opening balance' => $ledger['opening_balance'], 'Received' => $ledger['total_received'],
                'Issued' => $ledger['total_issued'], 'Closing balance' => $ledger['closing_balance'],
            ]);
        }

        return $this->respond($ledger);
    }
}
