<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMedicineCategoryRequest;
use App\Http\Requests\UpdateMedicineCategoryRequest;
use App\Http\Resources\MedicineCategoryListResource;
use App\Models\MedicineCategory;
use App\Models\MedicineCategoryModel;
use Illuminate\Http\Request;
use Laratrust\LaratrustFacade;

class MedicineCategoryController extends Controller
{
  public function index(){
    if(!LaratrustFacade::hasPermission('medicine-category-read')){
        return response()->json([
            'message'=>'permission denied',
            'status'=>403
        ]);
    }
        $search = request('search',false);
        $perPage =  request('per_page',10);
        $sortField = request('sort_field','updated_at');
        $sortDirection = request('sort_direction','desc');

        $query = MedicineCategory::query();
        $query->orderedBy($sortField,$sortDirection);
        if($search){
            $query->where('name','like',"%{$search}%")->orWhere('medicine_category_id','like',"%{$search}");
        }
        return MedicineCategoryListResource::collection($query->paginate($perPage));

  }
  public function store(StoreMedicineCategoryRequest $request){
    if(!LaratrustFacade::hasPermission('medicine-category-create')){
        return response()->json([
            'message'=>'permission denied',
            'status'=>403
        ]);
        }
        $validated = $request->validated();
        $medicineCategory = MedicineCategory::create($validated);
        return new MedicineCategoryListResource($medicineCategory);

}
public function update(UpdateMedicineCategoryRequest $request){
if(!LaratrustFacade::hasPermission('medicine-category-update')){
return response()->json([
    'message'=>'permission denied',
    'status'=>403
]);
}
    $validated = $request->validated();
        $medicineCategory = MedicineCategory::update($validated);
        return new MedicineCategoryListResource($medicineCategory);


}
public function show(MedicineCategory $medicineCategory){
       if(!LaratrustFacade::hasPermission('medicine-category-read')){
        return response()->json([
            'message'=>'permission denied',
            'status'=>403
        ]);
    }
    return new MedicineCategoryListResource($medicineCategory);
}
public function destroy(MedicineCategory $medicineCategory){
        if(!LaratrustFacade::hasPermission('medicine-category-delete')){
        return response()->json([
            'message'=>'permission denied',
            'status'=>403
        ]);
    }
        $medicineCategory->delete();
        return response()->json([
            'message'=>'medicine category deleted successfully '
        ]);

}
}
