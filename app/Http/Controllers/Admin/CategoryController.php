<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveCategoryRequest;
use App\Http\Resources\MedicineCategoryResource;
use App\Models\MedicineCategory;

class CategoryController extends Controller
{
    public function index()
    {
        return MedicineCategoryResource::collection(MedicineCategory::withCount('medicines')->orderBy('name')->get());
    }

    public function store(SaveCategoryRequest $request)
    {
        $this->authorize('create', MedicineCategory::class);

        return $this->respond(new MedicineCategoryResource(MedicineCategory::create($request->validated())), 'Category created.', 201);
    }

    public function update(SaveCategoryRequest $request, MedicineCategory $category)
    {
        $this->authorize('update', $category);
        $category->update($request->validated());

        return $this->respond(new MedicineCategoryResource($category), 'Category updated.');
    }

    public function destroy(MedicineCategory $category)
    {
        $this->authorize('delete', $category);
        $category->delete(); // medicines keep existing; category_id is nulled by the FK

        return $this->respond(null, 'Category deleted.');
    }
}
