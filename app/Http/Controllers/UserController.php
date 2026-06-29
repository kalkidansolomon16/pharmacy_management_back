<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserListResource;
// use Dotenv\Validator; // Usually not needed in Controllers
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
// use Illuminate\Testing\Fluent\Concerns\Has; // REMOVE THIS - This is for testing only
use Laratrust\Facades\Laratrust; // Correct Facade path

class UserController extends Controller
{
    public function index(Request $request)
    {
        $admin  = User::find(1);
        $admin->assignRole('pharmacy_admin');
        if (!auth()->user()->can('user-read')) {
            return response()->json(['message' => 'permission denied'], 403);
        }

        $search = request("search", false);
        $perPage = request('per_page', 10);
        $sortField = request('sort_field', 'updated_at');
        $sortDirection = request('sort_direction', 'desc');

        $query = User::query();


        // Note: Check if you have a custom scope 'orderedBy', otherwise use 'orderBy'
        $query->orderBy($sortField, $sortDirection);

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $query->where('id', '!=', Auth::id())
              ->where('id', '!=', 1);
              $userWithTenant = User::with('tenant')->find($request->user()->id);

        return UserListResource::collection($query->paginate($perPage));
    }

    public function store(StoreUserRequest $request)
    {
        // if (!Laratrust::hasPermission('user-create')) {
        //     return response()->json(['message' => 'permission denied'], 403);
        // }

        $validated = $request->validated();
        // $validated['name'] = ucfirst(strtolower($validated['name']));
        $validated['password'] = Hash::make($validated['password']);

        $user = User::create($validated);

        // Fixed logic: If you want to assign a role to the NEW user
        // $user->addRole('user');

        return new UserListResource($user);
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        if (!Laratrust::hasPermission('update-user')) {
            return response()->json(['message' => 'permission denied'], 403);
        }

        $validated = $request->validated();
        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']); // Don't overwrite with empty password
        }

        if ($user->id === Auth::id() || $user->id === 1) {
            return response()->json(['message' => 'you cannot modify this user'], 403);
        }

        $user->update($validated);

        return new UserListResource($user);
    }
}
