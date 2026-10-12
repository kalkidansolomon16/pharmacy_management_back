<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\SupplierReturnRequest;
use App\Http\Resources\SupplierReturnResource;
use App\Models\MedicineBatch;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Services\Inventory\SupplierReturnService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierReturnController extends Controller
{
    public function __construct(private SupplierReturnService $returns) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', SupplierReturn::class);

        return SupplierReturnResource::collection(SupplierReturn::with('supplier', 'batch.pharmacyMedicine.medicine', 'creator:id,name')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()->paginate($this->perPage(20)));
    }

    public function store(SupplierReturnRequest $request)
    {
        $return = $this->returns->create(MedicineBatch::findOrFail($request->input('batch_id')), (int) $request->input('quantity'),
            $request->input('reason'), $request->input('supplier_id') ? Supplier::find($request->input('supplier_id')) : null, $request->input('notes'));

        return $this->respond(new SupplierReturnResource($return), "Return {$return->return_number} recorded - stock removed.", 201);
    }

    public function resolve(Request $request, SupplierReturn $return)
    {
        $this->authorize('update', $return);
        $data = $request->validate(['status' => ['required', Rule::in(['credited', 'rejected'])], 'credit_note_number' => ['nullable', 'required_if:status,credited', 'string', 'max:60']]);

        return $this->respond(new SupplierReturnResource($this->returns->resolve($return, $data['status'], $data['credit_note_number'] ?? null)), 'Return updated.');
    }
}
