<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\SaveServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Service::class);

        return ServiceResource::collection(Service::when(! $request->boolean('all'), fn ($q) => $q->where('is_active', true))->orderBy('category')->orderBy('name')->get());
    }

    public function store(SaveServiceRequest $request)
    {
        return $this->respond(new ServiceResource(Service::create($request->validated())), 'Service added to the price list.', 201);
    }

    public function update(SaveServiceRequest $request, Service $service)
    {
        $service->update($request->validated());

        return $this->respond(new ServiceResource($service), 'Price list updated.');
    }
}
