<?php

namespace App\Http\Requests;

use App\Rules\MobileNumber;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tenant_id'=>'required',
            'email'=>'reqiured | string | email | max:255 | unique:users,email ',
            'password'=>['required | string | min:6 | regex:/[A-Z] | regex:/[a-z] | regex:/\d/ | regex:/[!@#$%^&*(),.?\":{}|<>]/, '],
            'status'=>'required | numeric',
            'address'=> 'required | string',
            'phone'=> ['required', new MobileNumber,Rule::unique('users','phone')]

        ];
    }
}
