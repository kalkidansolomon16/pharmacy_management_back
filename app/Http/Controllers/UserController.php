<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

use Laratrust\LaratrustFacade;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserListResource;
use Dotenv\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\Fluent\Concerns\Has;

class UserController extends Controller
{
    public function index(){
        if(!LaratrustFacade::hasPermission('user-read')){

return response()->json(['message'=>'permission denied',403]);
        }

        $search = request("search",false);
        $perPage = request('per_page',10);
        $sortField = request('sort_field','updated_at');
        $sortDirection = request('sort_direction','desc');

        $query = User::query();
    $query->orderedBy($sortField,$sortDirection);
    if($search){
        $query->where('name','like',"%{$search}%")->orWhere('email','like',"%{$search}%");


    }
    $query->where('id','!=',Auth::user()->id)
    ->where('id','!=',1);
    return UserListResource::collection($query->paginate($perPage));
    }
    public function show(User $user){
        if(!LaratrustFacade::hasPermission('user-read')){
            return response()->json([
                'message'=>'permission denied',
                'status'=>403
            ]);
        }
        return new UserListResource($user);
    }
    public function store(StoreUserRequest $request){
        if(!LaratrustFacade::hasPermission('user-create')){
            return response()->json([
                'message'=>'permission denied',
                'status'=>403
            ]);

        }
        $validated = $request->validated();
        $validated['name'] = ucfirst(strtolower($validated['name']));
        $validated['password'] = Hash::make($validated['password']);

        $user = User::create($validated);
       $adminn =  $user::find(1);
        $adminn->assignRole('super_admin');

        return new UserListResource($user);
    }
    public function update(UpdateUserRequest $request){
        if(!LaratrustFacade::hasPermission('update-user')){
            return response()->json([
                'message'=>'permission denied',
                'status'=>403
            ]);
        }
        $validated = $request->validated();
        $validated['name'] = ucfirst(strtolower($validated['name']));
        $validated['password'] = Has::make($validated['password']);

        if($request->id === Auth::user()->id || $request->id === 1 ){
            return response()->json([
                'message'=>'you can not delete this user',
            ]);
        }

        $user = User::update($validated);

        return new UserListResource($user);

    }
    public function destroy(User $user){
        if(!LaratrustFacade::hasPermission('user-delete')){
            return response()->json([
                'message'=>'permission denied',
                'status'=>403
            ]);
        }
        if(!$user){
            return response()->json([
                'message'=>'user not found',
            ]);
        }
        if($user->id === Auth::user()->id || $user->id === 1){
            return response()->json([
                'message'=>'You Can Not Delete Admin User'
            ]);
        }
        $user->delete();
        return response()->json([
            'message'=>'User deleted successfully',
        ]);

    }
}
