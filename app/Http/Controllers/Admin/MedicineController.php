<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveMedicineRequest;
use App\Http\Resources\MedicineResource;
use App\Models\Medicine;
use App\Models\PrescriptionItem;
use Illuminate\Http\Request;

/**
 * Master medicine catalogue. Readable by every signed-in user, writable by the platform team.
 */
class MedicineController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Medicine::class);

        $medicines = Medicine::query()
            ->with('category')
            ->search($request->query('search'))
            ->when($request->query('category_id'), fn ($q, $id) => $q->where('category_id', $id))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->when($request->boolean('rx_only'), fn ($q) => $q->where('prescription_required', true))
            ->withCount('listings')
            ->orderBy('generic_name')
            ->paginate($this->perPage(20));

        return MedicineResource::collection($medicines);
    }

    public function store(SaveMedicineRequest $request)
    {
        $this->authorize('create', Medicine::class);
        $medicine = Medicine::create($request->validated());

        return $this->respond(new MedicineResource($medicine->load('category')), 'Medicine added to the catalogue.', 201);
    }

    public function show(Medicine $medicine)
    {
        return new MedicineResource($medicine->load('category')->loadCount('listings'));
    }

    public function update(SaveMedicineRequest $request, Medicine $medicine)
    {
        $this->authorize('update', $medicine);
        $medicine->update($request->validated());

        return $this->respond(new MedicineResource($medicine->load('category')), 'Medicine updated.');
    }

    public function destroy(Medicine $medicine)
    {
        $this->authorize('delete', $medicine);

        // Medicines that are stocked or prescribed keep their history: deactivate instead
        if ($medicine->listings()->withoutGlobalScopes()->exists() || PrescriptionItem::where('medicine_id', $medicine->id)->exists()) {
            $medicine->update(['is_active' => false]);

            return $this->respond(new MedicineResource($medicine), 'Medicine is in use, so it was deactivated instead of deleted.');
        }

        $medicine->delete();

        return $this->respond(null, 'Medicine deleted.');
    }
}
