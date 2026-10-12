<?php

namespace App\Http\Requests\Clinical;

use Illuminate\Foundation\Http\FormRequest;

class TriageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('triage', $this->route('visit'));
    }

    public function rules(): array
    {
        return [
            'temperature' => ['nullable', 'numeric', 'between:30,45'],
            'pulse' => ['nullable', 'integer', 'between:20,250'],
            'respiratory_rate' => ['nullable', 'integer', 'between:5,80'],
            'bp_systolic' => ['nullable', 'integer', 'between:50,300'],
            'bp_diastolic' => ['nullable', 'integer', 'between:30,200'],
            'spo2' => ['nullable', 'integer', 'between:50,100'],
            'weight' => ['nullable', 'numeric', 'between:0.5,300'],
            'height' => ['nullable', 'numeric', 'between:30,250'],
            'muac' => ['nullable', 'numeric', 'between:5,50'], // mid-upper arm circumference (nutrition screening)
        ];
    }
}
