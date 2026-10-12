<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveMedicineRequest;
use App\Http\Resources\MedicineResource;
use App\Models\Medicine;
use App\Models\PrescriptionItem;
use App\Services\Catalog\CatalogContributionService;
use Illuminate\Http\Request;

/**
 * Master medicine catalogue. Readable by every signed-in user, writable by the platform team.
 * Pharmacies / hospitals with catalog.contribute add their own entries, which the platform team reviews.
 */
class MedicineController extends Controller
{
    public function __construct(private CatalogContributionService $catalog) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Medicine::class);

        $medicines = Medicine::query()
            ->visibleTo($request->user())
            ->with('category', 'contributor:id,name')
            ->when($request->query('review'), fn ($q, $status) => $q->where('review_status', $status))
            ->when($request->boolean('mine'), fn ($q) => $q->where('created_by_tenant_id', $request->user()->tenant_id))
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
        $medicine = $this->catalog->createMedicine($request->user(), $request->validated());

        return $this->respond(
            new MedicineResource($medicine->load('category')),
            $medicine->isApproved() ? 'Medicine added to the catalogue.' : 'Medicine added. You can stock it now; it joins the shared catalogue once the MedLink team approves it.',
            201,
        );
    }

    public function show(Medicine $medicine)
    {
        $this->authorize('view', $medicine);

        return new MedicineResource($medicine->load('category', 'contributor:id,name')->loadCount('listings'));
    }

    public function update(SaveMedicineRequest $request, Medicine $medicine)
    {
        $this->authorize('update', $medicine);
        $this->catalog->updateMedicine($request->user(), $medicine, $request->validated());

        return $this->respond(new MedicineResource($medicine->load('category')), $medicine->isApproved() ? 'Medicine updated.' : 'Medicine updated and sent for review.');
    }

    /** Platform team: approve a pharmacy's medicine for the shared catalogue, or reject it with a reason. */
    public function review(Request $request, Medicine $medicine)
    {
        $this->authorize('review', $medicine);
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:191']]);
        $this->catalog->review($medicine, $data['decision'] === 'approve', $data['note'] ?? null, $request->user());

        return $this->respond(new MedicineResource($medicine->load('category', 'contributor:id,name')), $data['decision'] === 'approve' ? 'Approved and shared with every pharmacy.' : 'Rejected. The pharmacy has been told why.');
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
