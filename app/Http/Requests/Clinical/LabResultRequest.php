<?php

namespace App\Http\Requests\Clinical;

use Illuminate\Foundation\Http\FormRequest;

class LabResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('process', $this->route('order'));
    }

    public function rules(): array
    {
        return [
            'result_value' => ['nullable', 'numeric', 'required_without:result_text'],
            'result_text' => ['nullable', 'string', 'max:191', 'required_without:result_value'],
            'remarks' => ['nullable', 'string', 'max:191'],
        ];
    }
}
