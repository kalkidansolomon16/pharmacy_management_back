<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request),[
        'id'=>$this->id,
        'tenant_id'=>$this->tenant_id,
        'email'=>$this->email,
        'password'=>$this->password,
        'status'=>$this->status,
        'address'=>$this->address,
        'phone'=>$this->phone
        ]);
    }
}
