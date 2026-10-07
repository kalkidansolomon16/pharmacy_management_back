<?php

namespace App\Http\Requests\Inventory;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add a catalogue medicine to the pharmacy's inventory (with an optional opening batch), or edit its price.
 */
class SaveListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $listing = $this->route('listing');

        return $listing ? $this->user()->can('update', $listing) : $this->user()->can('create', \App\Models\PharmacyMedicine::class);
    }

    public function rules(): array
    {
        $creating = ! $this->route('listing');

        return [
            'medicine_id' => [
                Rule::requiredIf($creating), Rule::prohibitedIf(! $creating),
                Rule::exists('medicines', 'id')->where('is_active', true),
                Rule::unique('pharmacy_medicines', 'medicine_id')->where('tenant_id', app(TenantContext::class)->id()),
            ],
            'price' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'reorder_level' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_public' => ['boolean'],
            // Optional opening stock when first listing the medicine
            'batch' => ['nullable', 'array', Rule::prohibitedIf(! $creating)],
            'batch.batch_number' => ['required_with:batch', 'string', 'max:60'],
            'batch.quantity' => ['required_with:batch', 'integer', 'min:1'],
            'batch.expiry_date' => ['required_with:batch', 'date', 'after:today'],
            'batch.purchase_price' => ['required_with:batch', 'numeric', 'min:0'],
            'batch.supplier' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return ['medicine_id.unique' => 'This medicine is already in your inventory.'];
    }
}
