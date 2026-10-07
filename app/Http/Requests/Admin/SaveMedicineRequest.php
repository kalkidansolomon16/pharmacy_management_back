<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveMedicineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $medicine = $this->route('medicine');

        return $medicine ? $this->user()->can('update', $medicine) : $this->user()->can('create', \App\Models\Medicine::class);
    }

    public function rules(): array
    {
        $medicine = $this->route('medicine');

        return [
            'category_id' => ['nullable', 'exists:medicine_categories,id'],
            'generic_name' => ['required', 'string', 'max:150'],
            'brand_name' => ['nullable', 'string', 'max:150'],
            'manufacturer' => ['nullable', 'string', 'max:150'],
            'dosage_form' => ['required', 'string', 'max:50'],
            'strength' => ['required', 'string', 'max:50'],
            'unit' => ['required', 'string', 'max:30'],
            'barcode' => ['nullable', 'string', 'max:64', Rule::unique('medicines', 'barcode')->ignore($medicine?->id)],
            'prescription_required' => ['boolean'],
            'is_controlled' => ['boolean'],
            'storage_conditions' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];
    }
}
