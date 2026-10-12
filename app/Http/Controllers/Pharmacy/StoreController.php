<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\SaveStoreRequest;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Store::class);
        Store::defaultFor($request->user()->tenant_id); // every organization has at least one store

        $stores = Store::query()
            ->withSum(['batches as units' => fn ($q) => $q->sellable()], 'quantity')
            ->withSum(['batches as stock_value' => fn ($q) => $q->sellable()], DB::raw('quantity * purchase_price'))
            ->orderByDesc('is_default')->orderBy('name')->get();

        return StoreResource::collection($stores);
    }

    public function store(SaveStoreRequest $request)
    {
        app(\App\Services\Platform\PlanLimits::class)->ensureCanAdd($request->user()->tenant, 'stores');
        $store = DB::transaction(function () use ($request) {
            if ($request->boolean('is_default')) {
                Store::query()->update(['is_default' => false]);
            }

            return Store::create($request->validated());
        });

        return $this->respond(new StoreResource($store), "{$store->name} created.", 201);
    }

    public function update(SaveStoreRequest $request, Store $store)
    {
        DB::transaction(function () use ($request, $store) {
            if ($request->boolean('is_default')) {
                Store::whereKeyNot($store->id)->update(['is_default' => false]);
            }
            $store->update($request->validated());
        });

        return $this->respond(new StoreResource($store), 'Store updated.');
    }
}
