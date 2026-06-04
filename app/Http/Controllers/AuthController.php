<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
   public function login(Request $request){
    $data = $request->validate([
        'email' =>'required |email ',
        'password'=>'required'
    ]);
    $user  = User::where('email',$data['email'])->first();
    if(!$user || !Hash::check($data['password'], $user->password)){

        return response()->json([
            'message'=>'invalid credentials',
            'status'=>401,
        ],401);
    };

    $token = $user->createToken('auth-token')->plainTextToken;
    return response()->json([
        'user'=>$user,
        'token'=>$token
    ]);
   }
   public function logout(Request $request){
    $request->user()->currentAccessToken()->delete();
    return response()->json([
        'message'=>'Logged out successfully'
    ]);

   }

}
