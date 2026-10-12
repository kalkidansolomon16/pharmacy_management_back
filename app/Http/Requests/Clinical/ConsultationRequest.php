<?php

namespace App\Http\Requests\Clinical;

use Illuminate\Foundation\Http\FormRequest;

class ConsultationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('consult', $this->route('visit'));
    }

    public function rules(): array
    {
        return [
            'chief_complaint' => ['nullable', 'string', 'max:1000'],
            'history' => ['nullable', 'string', 'max:5000'],
            'examination' => ['nullable', 'string', 'max:5000'],
            'plan' => ['nullable', 'string', 'max:3000'],
            'diagnoses' => ['nullable', 'array', 'max:10'],
            'diagnoses.*.code' => ['required', 'string', 'max:10'],
            'diagnoses.*.name' => ['required', 'string', 'max:191'],
        ];
    }
}
