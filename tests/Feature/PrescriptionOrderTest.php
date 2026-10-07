<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrescriptionOrderTest extends TestCase
{
    private function setUpScenario(int $stock = 100): array
    {
        $hospital = $this->tenant('hospital');
        $doctor = $this->userWithRole(User::ROLE_DOCTOR, $hospital);
        $patient = $this->patient($hospital);

        $pharmacy = $this->tenant('pharmacy');
        $staff = $this->userWithRole(User::ROLE_STAFF, $pharmacy);
        $amoxicillin = $this->medicine(['generic_name' => 'Amoxicillin', 'prescription_required' => true]);
        $listing = $this->listing($pharmacy, $amoxicillin, [[$stock, 300]], 5);

        $customer = $this->userWithRole(User::ROLE_CUSTOMER, null, ['phone' => '+251911223344']);

        return compact('hospital', 'doctor', 'patient', 'pharmacy', 'staff', 'amoxicillin', 'listing', 'customer');
    }

    private function issue(User $doctor, $patient, $medicine, int $quantity = 21): string
    {
        Sanctum::actingAs($doctor);

        return $this->postJson('/api/prescriptions', [
            'patient_id' => $patient->id,
            'diagnosis' => 'Pharyngitis',
            'items' => [[
                'medicine_id' => $medicine->id, 'dosage' => '1 capsule', 'frequency' => '3 times daily',
                'duration_days' => 7, 'total_quantity' => $quantity,
            ]],
        ])->assertCreated()->json('data.reference_code');
    }

    private function order(array $s, int $quantity, array $extra = [])
    {
        Sanctum::actingAs($s['customer']);

        return $this->postJson('/api/my/orders', [
            'pharmacy_id' => $s['pharmacy']->id,
            'items' => [['pharmacy_medicine_id' => $s['listing']->id, 'quantity' => $quantity]],
            'fulfillment' => 'pickup',
            'payment_method' => 'telebirr',
        ] + $extra);
    }

    public function test_prescription_only_medicine_requires_a_prescription(): void
    {
        $s = $this->setUpScenario();

        $this->order($s, 10)->assertUnprocessable()
            ->assertJsonPath('message', 'Some items in this order cannot be fulfilled.');
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    public function test_prescribed_quantity_is_enforced_across_orders(): void
    {
        $s = $this->setUpScenario();
        $code = $this->issue($s['doctor'], $s['patient'], $s['amoxicillin'], 21);

        // More than prescribed
        $this->order($s, 30, ['prescription_code' => $code, 'patient_phone' => '0911223344'])->assertUnprocessable();

        // Wrong phone: cannot use someone else's prescription
        $this->order($s, 21, ['prescription_code' => $code, 'patient_phone' => '0999999999'])
            ->assertUnprocessable()->assertJsonValidationErrors('patient_phone');

        // Exactly as prescribed
        $orderId = $this->order($s, 21, ['prescription_code' => $code, 'patient_phone' => '0911223344'])
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($s['staff']);
        $this->postJson("/api/orders/{$orderId}/confirm")->assertOk();
        $this->postJson("/api/orders/{$orderId}/complete")->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertSame(21, PrescriptionItem::first()->dispensed_quantity);
        $this->assertSame('dispensed', Prescription::withoutGlobalScopes()->first()->status);

        // The prescription is used up
        $this->order($s, 1, ['prescription_code' => $code, 'patient_phone' => '0911223344'])->assertUnprocessable();
    }

    public function test_expired_prescription_is_rejected(): void
    {
        $s = $this->setUpScenario();
        $code = $this->issue($s['doctor'], $s['patient'], $s['amoxicillin']);
        Prescription::withoutGlobalScopes()->update(['expires_at' => now()->subDay()]);

        $this->order($s, 5, ['prescription_code' => $code, 'patient_phone' => '0911223344'])
            ->assertUnprocessable()->assertJsonValidationErrors('prescription_code');
        $this->assertSame('expired', Prescription::withoutGlobalScopes()->first()->status);
    }

    public function test_partial_fulfillment_charges_only_what_was_dispensed(): void
    {
        $s = $this->setUpScenario(stock: 30);
        $code = $this->issue($s['doctor'], $s['patient'], $s['amoxicillin'], 21);
        $orderId = $this->order($s, 21, ['prescription_code' => $code, 'patient_phone' => '0911223344'])->assertCreated()->json('data.id');

        // Meanwhile a counter sale (with its own prescription) consumes most of the stock
        $code2 = $this->issue($s['doctor'], $this->patient($s['hospital'], ['phone' => '+251922000000', 'full_name' => 'Meron Alemu']), $s['amoxicillin'], 21);
        Sanctum::actingAs($s['staff']);
        $this->postJson('/api/pos/sales', [
            'items' => [['pharmacy_medicine_id' => $s['listing']->id, 'quantity' => 21]],
            'prescription_code' => $code2,
            'payment_method' => 'cash',
        ])->assertCreated();

        // Only 9 left for a 21-unit order
        $this->postJson("/api/orders/{$orderId}/complete")->assertUnprocessable();
        $this->postJson("/api/orders/{$orderId}/complete", ['allow_partial' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'partially_completed')
            ->assertJsonPath('data.total_amount', 45); // 9 x 5 ETB

        $item = PrescriptionItem::whereHas('prescription', fn ($q) => $q->where('reference_code', $code))->first();
        $this->assertSame(9, $item->dispensed_quantity);
        $this->assertSame('partially_dispensed', Prescription::withoutGlobalScopes()->where('reference_code', $code)->value('status'));
    }

    public function test_order_lifecycle_rejects_invalid_transitions(): void
    {
        $s = $this->setUpScenario();
        $otc = $this->listing($s['pharmacy'], $this->medicine(['generic_name' => 'Paracetamol']));

        Sanctum::actingAs($s['customer']);
        $orderId = $this->postJson('/api/my/orders', [
            'pharmacy_id' => $s['pharmacy']->id,
            'items' => [['pharmacy_medicine_id' => $otc->id, 'quantity' => 2]],
            'fulfillment' => 'pickup',
            'payment_method' => 'cash',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($s['staff']);
        $this->postJson("/api/orders/{$orderId}/ready")->assertUnprocessable(); // must be confirmed first
        $this->postJson("/api/orders/{$orderId}/reject", ['reason' => 'Closed today'])->assertOk();
        $this->postJson("/api/orders/{$orderId}/confirm")->assertUnprocessable();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $s['customer']->id, 'type' => 'App\\Notifications\\OrderStatusChanged']);
    }

    public function test_public_prescription_check_requires_matching_phone(): void
    {
        $s = $this->setUpScenario();
        $code = $this->issue($s['doctor'], $s['patient'], $s['amoxicillin']);
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/public/prescriptions/verify', ['code' => $code, 'phone' => '0900000000'])->assertNotFound();
        $this->postJson('/api/public/prescriptions/verify', ['code' => $code, 'phone' => '0911 223 344'])
            ->assertOk()
            ->assertJsonPath('data.prescription.patient.full_name', 'Abebe K.')
            ->assertJsonMissingPath('data.prescription.diagnosis')
            ->assertJsonCount(1, 'data.pharmacies_with_all_items');
    }
}
