<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\SaveSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Supplier::class);

        return SupplierResource::collection(Supplier::withCount('purchaseOrders')
            ->when($request->query('search'), fn ($q, $t) => $q->where('name', 'like', "%{$t}%"))
            ->orderBy('name')->get());
    }

    public function store(SaveSupplierRequest $request)
    {
        return $this->respond(new SupplierResource(Supplier::create($request->validated())), 'Supplier added.', 201);
    }

    public function update(SaveSupplierRequest $request, Supplier $supplier)
    {
        $supplier->update($request->validated());

        return $this->respond(new SupplierResource($supplier), 'Supplier updated.');
    }
}
