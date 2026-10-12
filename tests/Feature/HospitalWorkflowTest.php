<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\LabTest;
use App\Models\Payer;
use App\Models\PayerMembership;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

class HospitalWorkflowTest extends TestCase
{
    private Tenant $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hospital = $this->tenant('hospital', [
            'tin_number' => '0045678912',
            'settings' => ['payment_before_service' => true],
        ]);
        foreach ([['REG', 'Card', 'registration', 50], ['CONS-OPD', 'OPD consultation', 'consultation', 150], ['PROC-ECG', 'ECG', 'procedure', 300]] as [$code, $name, $cat, $price]) {
            Service::withoutGlobalScopes()->create(['tenant_id' => $this->hospital->id, 'code' => $code, 'name' => $name, 'category' => $cat, 'price' => $price, 'tax_category' => 'exempt', 'is_active' => true]);
        }
    }

    private function staff(string $role): User
    {
        return $this->userWithRole($role, $this->hospital);
    }

    private function labTest(array $attributes = []): LabTest
    {
        return LabTest::withoutGlobalScopes()->create($attributes + [
            'tenant_id' => $this->hospital->id, 'code' => 'FBS', 'name' => 'Fasting blood sugar', 'category' => 'chemistry',
            'unit' => 'mg/dL', 'result_type' => 'numeric', 'reference_low' => 70, 'reference_high' => 110,
            'critical_low' => 50, 'critical_high' => 400, 'price' => 60, 'is_active' => true,
        ]);
    }

    public function test_check_in_bills_card_and_consultation_and_the_patient_moves_through_the_visit(): void
    {
        $patient = $this->patient($this->hospital);
        $doctor = $this->staff(User::ROLE_DOCTOR);

        $visitId = $this->actingAs($this->staff(User::ROLE_RECEPTIONIST))
            ->postJson('/api/visits', ['patient_id' => $patient->id, 'type' => 'opd', 'chief_complaint' => 'Fever'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'waiting')
            ->json('data.id');

        $invoice = Invoice::withoutGlobalScopes()->where('visit_id', $visitId)->firstOrFail();
        $this->assertEquals(200, (float) $invoice->total); // new patient: card 50 + consultation 150

        // A second open visit for the same patient is refused
        $this->actingAs($this->staff(User::ROLE_RECEPTIONIST))
            ->postJson('/api/visits', ['patient_id' => $patient->id, 'type' => 'opd'])
            ->assertStatus(422);

        $this->actingAs($this->staff(User::ROLE_NURSE))
            ->postJson("/api/visits/{$visitId}/triage", ['temperature' => 39.8, 'pulse' => 110, 'spo2' => 95])
            ->assertOk()
            ->assertJsonPath('data.priority', 'urgent');

        $this->actingAs($doctor)->postJson("/api/visits/{$visitId}/start")->assertOk()->assertJsonPath('data.status', 'in_consultation');

        // A diagnosis is required before closing (HMIS / DHIS2 reporting)
        $this->actingAs($doctor)->postJson("/api/visits/{$visitId}/complete")->assertStatus(422);
        $this->actingAs($doctor)->putJson("/api/visits/{$visitId}/notes", [
            'history' => 'Fever for 3 days', 'diagnoses' => [['code' => 'B54', 'name' => 'Malaria, unspecified']],
        ])->assertOk();
        $this->actingAs($doctor)->postJson("/api/visits/{$visitId}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_the_lab_waits_for_payment_and_flags_critical_results(): void
    {
        $patient = $this->patient($this->hospital);
        $doctor = $this->staff(User::ROLE_DOCTOR);
        $lab = $this->staff(User::ROLE_LAB_TECHNICIAN);
        $test = $this->labTest();

        $order = $this->actingAs($doctor)->postJson('/api/lab-orders', ['patient_id' => $patient->id, 'tests' => [$test->id]])
            ->assertCreated()->json('data');

        $this->actingAs($lab)->postJson("/api/lab-orders/{$order['id']}/collect")
            ->assertStatus(422)->assertJsonValidationErrors('payment');

        $invoice = Invoice::withoutGlobalScopes()->where('patient_id', $patient->id)->firstOrFail();
        $this->actingAs($this->staff(User::ROLE_CASHIER))
            ->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 60, 'method' => 'telebirr', 'reference' => 'TB123'])
            ->assertOk()->assertJsonPath('data.status', 'paid');

        $this->actingAs($lab)->postJson("/api/lab-orders/{$order['id']}/collect")->assertOk();
        $itemId = $order['items'][0]['id'];
        $this->actingAs($lab)->postJson("/api/lab-orders/{$order['id']}/items/{$itemId}/result", ['result_value' => 455])
            ->assertOk()->assertJsonPath('data.items.0.flag', 'critical');

        $this->assertTrue($doctor->notifications()->where('type', \App\Notifications\CriticalLabResult::class)->exists());

        $this->actingAs($lab)->postJson("/api/lab-orders/{$order['id']}/items/{$itemId}/verify")->assertOk();
        $this->actingAs($lab)->postJson("/api/lab-orders/{$order['id']}/items/{$itemId}/result", ['result_value' => 90])
            ->assertStatus(422); // verified results are locked
    }

    public function test_insurance_splits_the_bill_and_large_bills_need_pre_authorization(): void
    {
        $patient = $this->patient($this->hospital);
        $payer = Payer::withoutGlobalScopes()->create([
            'tenant_id' => $this->hospital->id, 'name' => 'Nyala Insurance', 'code' => 'NYALA', 'type' => 'private_insurance',
            'default_copay_percent' => 10, 'preauth_threshold' => 500, 'payment_terms_days' => 30, 'is_active' => true,
        ]);
        $membership = PayerMembership::withoutGlobalScopes()->create([
            'tenant_id' => $this->hospital->id, 'payer_id' => $payer->id, 'patient_id' => $patient->id, 'member_number' => 'NYL-1', 'beneficiary_name' => $patient->full_name,
            'valid_from' => today()->subMonth(), 'valid_to' => today()->addYear(), 'status' => 'active',
        ]);
        $ecg = Service::withoutGlobalScopes()->where('code', 'PROC-ECG')->first();
        $cashier = $this->staff(User::ROLE_CASHIER);
        $admin = $this->staff(User::ROLE_HOSPITAL_ADMIN);

        $small = $this->actingAs($admin)->postJson('/api/invoices', [
            'patient_id' => $patient->id, 'membership_id' => $membership->id, 'lines' => [['service_id' => $ecg->id, 'quantity' => 1]],
        ])->assertCreated()->json('data');
        $this->assertEquals(270, $small['payer_share']);
        $this->assertEquals(30, $small['patient_share']);
        $this->assertSame('issued', $small['status']);

        $large = $this->actingAs($admin)->postJson('/api/invoices', [
            'patient_id' => $patient->id, 'membership_id' => $membership->id, 'lines' => [['service_id' => $ecg->id, 'quantity' => 3]],
        ])->assertCreated()->json('data');
        $this->assertSame('awaiting_approval', $large['status']);

        $authorizationId = $this->actingAs($admin)->getJson('/api/authorizations')->assertOk()->json('data.0.id');
        $this->actingAs($admin)->postJson("/api/authorizations/{$authorizationId}/decide", ['approved' => true, 'reference_number' => 'NY-AUTH-77'])->assertOk();
        $this->assertSame('issued', Invoice::withoutGlobalScopes()->find($large['id'])->status);

        // The patient pays the co-pay; the insurer's share goes on the monthly claim
        $this->actingAs($cashier)->postJson("/api/invoices/{$small['id']}/payments", ['amount' => 30, 'method' => 'cash'])->assertOk();
        $claim = $this->actingAs($admin)->postJson('/api/claims', [
            'payer_id' => $payer->id, 'from' => today()->startOfMonth()->toDateString(), 'to' => today()->toDateString(),
        ])->assertCreated()->json('data');
        $this->assertEquals(270 + 810, $claim['total_amount']);
        $this->assertSame(2, $claim['invoices_count']);
    }

    public function test_amounts_an_insurer_rejects_are_written_off_and_no_longer_owed(): void
    {
        $patient = $this->patient($this->hospital);
        $payer = Payer::withoutGlobalScopes()->create([
            'tenant_id' => $this->hospital->id, 'name' => 'Nyala Insurance', 'code' => 'NYALA', 'type' => 'private_insurance',
            'default_copay_percent' => 0, 'payment_terms_days' => 30, 'is_active' => true,
        ]);
        $membership = PayerMembership::withoutGlobalScopes()->create([
            'tenant_id' => $this->hospital->id, 'payer_id' => $payer->id, 'patient_id' => $patient->id, 'member_number' => 'NYL-2',
            'beneficiary_name' => $patient->full_name, 'valid_from' => today()->subMonth(), 'valid_to' => today()->addYear(), 'status' => 'active',
        ]);
        $ecg = Service::withoutGlobalScopes()->where('code', 'PROC-ECG')->first();
        $admin = $this->staff(User::ROLE_HOSPITAL_ADMIN);

        $this->actingAs($admin)->postJson('/api/invoices', ['patient_id' => $patient->id, 'membership_id' => $membership->id, 'lines' => [['service_id' => $ecg->id, 'quantity' => 1]]])->assertCreated();
        $claim = $this->actingAs($admin)->postJson('/api/claims', ['payer_id' => $payer->id, 'from' => today()->startOfMonth()->toDateString(), 'to' => today()->toDateString()])->json('data');
        $this->actingAs($admin)->postJson("/api/claims/{$claim['id']}/submit")->assertOk();

        // Insurer pays 200 of the 300 and refuses 100
        $this->actingAs($admin)->postJson("/api/claims/{$claim['id']}/payments", ['amount' => 200, 'rejected' => 100, 'method' => 'bank_transfer'])
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.rejected_amount', 100);

        $this->actingAs($admin)->getJson('/api/receivables')->assertJsonPath('data.payer_total', 0)->assertJsonCount(0, 'data.payers');
        $invoice = Invoice::withoutGlobalScopes()->where('patient_id', $patient->id)->first();
        $this->assertSame(0.0, $invoice->payerBalance());
        $this->assertEquals(100, (float) $invoice->payer_rejected);
    }

    public function test_e_invoices_are_registered_with_irn_and_qr(): void
    {
        $patient = $this->patient($this->hospital);
        $invoice = $this->actingAs($this->staff(User::ROLE_HOSPITAL_ADMIN))->postJson('/api/invoices', [
            'patient_id' => $patient->id, 'lines' => [['description' => 'Medical certificate', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated()->json('data');

        $fiscal = Invoice::withoutGlobalScopes()->find($invoice['id']);
        $this->assertSame('accepted', $fiscal->fiscal_status);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/i', $fiscal->irn);
        $this->assertNotEmpty($fiscal->rrn);
        $this->assertStringContainsString($fiscal->irn, $fiscal->fiscal_qr);
        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{6}$/', $fiscal->invoice_number);
    }

    public function test_unreachable_e_invoices_queue_and_are_retried_by_the_sync_command(): void
    {
        config(['einvoice.sandbox_failure_rate' => 1]); // the MoR endpoint is unreachable (offline / power cut)
        $patient = $this->patient($this->hospital);
        $id = $this->actingAs($this->staff(User::ROLE_HOSPITAL_ADMIN))->postJson('/api/invoices', [
            'patient_id' => $patient->id, 'lines' => [['description' => 'Dressing', 'quantity' => 1, 'unit_price' => 120]],
        ])->assertCreated()->json('data.id');
        $this->assertSame('pending', Invoice::withoutGlobalScopes()->find($id)->fiscal_status);

        config(['einvoice.sandbox_failure_rate' => 0]);
        $this->artisan('einvoice:sync')->assertSuccessful();
        $this->assertSame('accepted', Invoice::withoutGlobalScopes()->find($id)->fiscal_status);
    }

    public function test_rejected_e_invoices_can_be_resubmitted_after_correction(): void
    {
        $this->hospital->update(['tin_number' => '123']); // the sandbox rejects malformed TINs
        $patient = $this->patient($this->hospital);
        $admin = $this->staff(User::ROLE_HOSPITAL_ADMIN);
        $id = $this->actingAs($admin)->postJson('/api/invoices', [
            'patient_id' => $patient->id, 'lines' => [['description' => 'Dressing', 'quantity' => 1, 'unit_price' => 120]],
        ])->assertCreated()->json('data.id');
        $this->assertSame('failed', Invoice::withoutGlobalScopes()->find($id)->fiscal_status);

        $this->hospital->update(['tin_number' => '0045678912']);
        $this->actingAs($admin)->postJson("/api/invoices/{$id}/fiscal")->assertOk()->assertJsonPath('data.fiscal.status', 'accepted');
    }

    public function test_a_hospital_without_the_pharmacy_module_cannot_use_pharmacy_routes(): void
    {
        $admin = $this->staff(User::ROLE_HOSPITAL_ADMIN);
        $this->actingAs($admin)->getJson('/api/inventory')->assertForbidden()->assertJsonPath('code', 'module_disabled');

        $this->hospital->update(['modules' => [...$this->hospital->enabledModules(), 'pharmacy']]);
        $this->actingAs($admin->fresh())->getJson('/api/stores')->assertOk();
    }
}
