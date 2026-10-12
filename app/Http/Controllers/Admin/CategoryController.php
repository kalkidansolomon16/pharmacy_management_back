<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveCategoryRequest;
use App\Http\Resources\MedicineCategoryResource;
use App\Models\MedicineCategory;
use App\Services\Catalog\CatalogContributionService;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(private CatalogContributionService $catalog) {}

    public function index(Request $request)
    {
        return MedicineCategoryResource::collection(
            MedicineCategory::visibleTo($request->user())->with('contributor:id,name')->withCount('medicines')->orderBy('name')->get()
        );
    }

    /** Platform team: approve or reject a category a pharmacy added. */
    public function review(Request $request, MedicineCategory $category)
    {
        $this->authorize('review', $category);
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:191']]);
        $this->catalog->review($category, $data['decision'] === 'approve', $data['note'] ?? null, $request->user());

        return $this->respond(new MedicineCategoryResource($category->refresh()), $data['decision'] === 'approve' ? 'Category approved.' : 'Category rejected.');
    }

    public function store(SaveCategoryRequest $request)
    {
        $this->authorize('create', MedicineCategory::class);

        $category = $this->catalog->createCategory($request->user(), $request->validated());

        return $this->respond(new MedicineCategoryResource($category), $category->review_status === 'approved' ? 'Category created.' : 'Category added for your pharmacy; the MedLink team will review it.', 201);
    }

    public function update(SaveCategoryRequest $request, MedicineCategory $category)
    {
        $this->authorize('update', $category);
        $category->update($request->user()->can('catalog.manage') ? $request->validated() : $request->validated() + ['review_status' => 'pending', 'review_note' => null]);

        return $this->respond(new MedicineCategoryResource($category), 'Category updated.');
    }

    public function destroy(MedicineCategory $category)
    {
        $this->authorize('delete', $category);
        $category->delete(); // medicines keep existing; category_id is nulled by the FK

        return $this->respond(null, 'Category deleted.');
    }
}
