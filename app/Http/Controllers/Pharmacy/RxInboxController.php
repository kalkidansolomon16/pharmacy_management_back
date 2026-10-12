<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrescriptionResource;
use App\Models\Prescription;
use Illuminate\Http\Request;

/**
 * E-prescriptions sent to this pharmacy (from its own hospital or partner prescribers).
 */
class RxInboxController extends Controller
{
    public function __invoke(Request $request)
    {
        $this->authorize('prescriptions.dispense');

        $prescriptions = Prescription::acrossTenants()
            ->with('patient', 'doctor:id,name,license_number', 'hospital:id,name', 'items.medicine')
            ->where('pharmacy_id', $request->user()->tenant_id)
            ->when($request->query('status', 'open') === 'open', fn ($q) => $q->dispensable(), fn ($q) => $q->where('status', $request->query('status')))
            ->latest('issued_at')
            ->paginate($this->perPage(20));

        return PrescriptionResource::collection($prescriptions);
    }
}
