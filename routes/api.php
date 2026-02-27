<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\MedicineCategoryController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::apiResource('users',UserController::class);
Route::post('login',[AuthController::class,'login']);
Route::middleware('auth:sanctum')->group(function(){
Route::get('users',[UserController::class,'index']);
Route::post('users',[UserController::class,'store']);
Route::get('users/${id}',[UserController::class,'show']);
Route::delete('user/${id}',[UserController::class,'destroy']);

Route::get('tenants',[TenantController::class,'index']);
Route::post('tenants',[TenantController::class,'store']);
Route::get('tenants/${id}',[TenantController::class,'show']);
Route::delete('tenant/${id}',[TenantController::class,'destroy']);

Route::get('medicine-categories',[MedicineCategoryController::class,'index']);
Route::post('medicine-categories',[MedicineCategoryController::class,'store']);
Route::get('medicine-categories/${id}',[MedicineCategoryController::class,'show']);
Route::delete('medicine-category/${id}',[MedicineCategoryController::class,'destroy']);

});
