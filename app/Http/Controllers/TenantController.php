<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTenantRequest;
use App\Http\Requests\UpdateTenantRequest;
use App\Http\Resources\TenantListResource;
use App\Models\Tenant;
use App\Models\User;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Request;
use Laratrust\LaratrustFacade;

class TenantController extends Controller
{
    public function index(){
        if(!LaratrustFacade::hasPermission('tenant-read')){
            return response()->json([
                'message'=>'permission denied',
                'status'=>403
            ]);
             }
            $search = request('search',false);
            $perPage = request('per_page',10);
            $sortField = request('sort_field','updated_at');
            $sortDirection = request('sort_direction','desc');
            $query = User::query();
            $query->orderedBy($sortField, $sortDirection);

            if($search){
                $query->where('name','like',"%{$search}%")->orWhere('type','like',"%{$search}%")->orWhere('statis','like',"%{$search}%");

            }

            return TenantListResource::collection($query->paginate($perPage));
    }
    public function store(StoreTenantRequest $request){
        if(!LaratrustFacade::hasPermission('tenant-create')){
            return response()->json([
                'message'=>'permission denied',
                'status'=>403
            ]);
            $validated  = $request->validated();
            $validated['name'] = ucfirst(strtolower($validated['name']));
            $tenant = Tenant::create($validated);
            return new TenantListResource($tenant);

        }
    }
    public function update(UpdateTenantRequest $request){
if(!LaratrustFacade::hasPermission('tenant-update')){
    return response()->json([
        'message'=>'permission denied',
        'status'=>403,
    ]);
    $validated = $request->validated();
    $tenant = Tenant::update($validated);

    return new TenantListResource($tenant);
}
    }
    public function show(Tenant $tenant){
   if(!LaratrustFacade::hasPermission('tenant-read')){
            return response()->json([
                'message'=>'permission denied',
                'status'=>403
            ]);
             }
             return new TenantListResource($tenant);
    }
    public function destroy(Tenant $tenant){
        if(!LaratrustFacade::hasPermisssion('tenant-delete')){
            return response()->json([
                'message'=>'permission denied',
                'status'=>403
            ]);
        }
        if(!$tenant){
            return response()->json([
                'message'=>'user not found',

            ]);
            $tenant->delete();
            return response()->json([
                'message'=>'tenant deleted successfully',
                'status'=>200
            ]);
        }
    }
}
