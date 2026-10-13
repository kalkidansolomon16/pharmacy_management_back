<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

/** Hospitals can ask patients to pay the card / consultation fee before the doctor sees them. */
class CardFeeBeforeConsultationTest extends TestCase
{
    private function hospital(bool $required): Tenant
    {
        $hospital = $this->tenant('hospital', ['settings' => ['card_fee_before_consultation' => $required]]);
        foreach ([['REG', 'Card', 'registration', 50], ['CONS-OPD', 'OPD consultation', 'consultation', 150], ['CONS-EMG', 'Emergency consultation', 'consultation', 250]] as [$code, $name, $cat, $price]) {
            Service::withoutGlobalScopes()->create(['tenant_id' => $hospital->id, 'code' => $code, 'name' => $name, 'category' => $cat, 'price' => $price, 'tax_category' => 'exempt', 'is_active' => true]);
        }

        return $hospital;
    }

    private function checkIn(Tenant $hospital, string $type = 'opd'): int
    {
        return $this->actingAs($this->userWithRole(User::ROLE_RECEPTIONIST, $hospital))
            ->postJson('/api/visits', ['patient_id' => $this->patient($hospital)->id, 'type' => $type, 'chief_complaint' => 'Headache'])
            ->assertCreated()->json('data.id');
    }

    public function test_the_doctor_waits_until_the_card_fee_is_paid(): void
    {
        $hospital = $this->hospital(true);
        $doctor = $this->userWithRole(User::ROLE_DOCTOR, $hospital);
        $visitId = $this->checkIn($hospital);

        // The queue shows what is owed
        $this->actingAs($doctor)->getJson('/api/visits')->assertJsonPath('data.0.fees_due.amount', 200);

        $this->actingAs($doctor)->postJson("/api/visits/{$visitId}/start")
            ->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->actingAs($doctor)->putJson("/api/visits/{$visitId}/notes", ['history' => 'x'])->assertUnprocessable();

        // Part payment is not enough
        $invoice = Invoice::withoutGlobalScopes()->where('visit_id', $visitId)->firstOrFail();
        $cashier = $this->userWithRole(User::ROLE_CASHIER, $hospital);
        $this->actingAs($cashier)->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 50, 'method' => 'cash'])->assertOk();
        $this->actingAs($doctor)->postJson("/api/visits/{$visitId}/start")->assertUnprocessable();

        $this->actingAs($cashier)->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 150, 'method' => 'telebirr', 'reference' => 'TB1'])->assertOk();
        $this->actingAs($doctor)->getJson("/api/visits/{$visitId}")->assertJsonPath('data.fees_due', null);
        $this->actingAs($doctor)->postJson("/api/visits/{$visitId}/start")->assertOk()->assertJsonPath('data.status', 'in_consultation');
    }

    public function test_emergencies_and_hospitals_without_the_rule_are_never_blocked(): void
    {
        $hospital = $this->hospital(true);
        $doctor = $this->userWithRole(User::ROLE_DOCTOR, $hospital);
        $emergency = $this->checkIn($hospital, 'emergency');
        $this->actingAs($doctor)->postJson("/api/visits/{$emergency}/start")->assertOk();

        $relaxed = $this->hospital(false);
        $visitId = $this->checkIn($relaxed);
        $this->actingAs($this->userWithRole(User::ROLE_DOCTOR, $relaxed))->postJson("/api/visits/{$visitId}/start")->assertOk();
    }
}
