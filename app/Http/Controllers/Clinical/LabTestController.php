<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinical\SaveLabTestRequest;
use App\Http\Resources\LabTestResource;
use App\Models\LabTest;
use Illuminate\Http\Request;

class LabTestController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', LabTest::class);

        return LabTestResource::collection(LabTest::query()
            ->when(! $request->boolean('all'), fn ($q) => $q->where('is_active', true))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%{$t}%")->orWhere('code', 'like', "%{$t}%")))
            ->orderBy('category')->orderBy('name')->get());
    }

    public function store(SaveLabTestRequest $request)
    {
        return $this->respond(new LabTestResource(LabTest::create($request->validated())), 'Test added to the lab menu.', 201);
    }

    public function update(SaveLabTestRequest $request, LabTest $test)
    {
        $test->update($request->validated());

        return $this->respond(new LabTestResource($test), 'Test updated.');
    }
}
