<?php

namespace App\Http\Requests\Clinical;

use App\Models\LabTest;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveLabTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $test = $this->route('test');

        return $test ? $this->user()->can('update', $test) : $this->user()->can('create', LabTest::class);
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('lab_tests', 'code')->where('tenant_id', app(TenantContext::class)->id())->ignore($this->route('test')?->id)],
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(LabTest::CATEGORIES)],
            'specimen' => ['nullable', 'string', 'max:30'],
            'unit' => ['nullable', 'string', 'max:20'],
            'result_type' => ['required', Rule::in(['numeric', 'text', 'positive_negative', 'select'])],
            'options' => ['nullable', 'array'],
            'options.*' => ['string', 'max:60'],
            'reference_low' => ['nullable', 'numeric'],
            'reference_high' => ['nullable', 'numeric', 'gte:reference_low'],
            'critical_low' => ['nullable', 'numeric'],
            'critical_high' => ['nullable', 'numeric'],
            'reference_text' => ['nullable', 'string', 'max:120'],
            'price' => ['required', 'numeric', 'min:0'],
            'turnaround_minutes' => ['nullable', 'integer', 'min:5', 'max:10080'],
            'is_active' => ['boolean'],
        ];
    }
}
