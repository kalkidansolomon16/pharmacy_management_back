<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\MedicineBatch;
use App\Models\Order;
use App\Models\User;
use Tests\TestCase;

/** Pharmacies that want payments to pass through a cashier: the pharmacist prepares, the cashier collects. */
class PayAtCashierTest extends TestCase
{
    public function test_the_pharmacist_sends_the_sale_and_the_cashier_collects_it(): void
    {
        $pharmacy = $this->tenant('pharmacy', ['settings' => ['pay_at_cashier' => true]]);
        $pharmacist = $this->userWithRole(User::ROLE_STAFF, $pharmacy);
        $cashier = $this->userWithRole(User::ROLE_CASHIER, $pharmacy);
        $listing = $this->listing($pharmacy, batches: [[100, 365]], price: 25);

        $order = $this->actingAs($pharmacist)->postJson('/api/pos/sales', [
            'items' => [['pharmacy_medicine_id' => $listing->id, 'quantity' => 4]],
            'payment_method' => 'cash', 'customer_name' => 'Almaz',
        ])->assertCreated()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.payment_status', 'unpaid')->json('data');

        // Nothing leaves the shelf and no receipt exists until the money is taken
        $this->assertSame(100, (int) MedicineBatch::withoutGlobalScopes()->sum('quantity'));
        $this->assertSame(0, Invoice::withoutGlobalScopes()->count());
        // ...and it cannot be completed from the orders page either
        $this->actingAs($pharmacist)->postJson("/api/orders/{$order['id']}/complete")->assertUnprocessable();

        $this->actingAs($cashier)->getJson('/api/pos/counter-sales')->assertOk()
            ->assertJsonPath('meta.waiting', 1)->assertJsonPath('data.0.customer_name', 'Almaz');

        $this->actingAs($cashier)->postJson("/api/pos/counter-sales/{$order['id']}/pay", ['method' => 'telebirr', 'reference' => 'TB-778'])
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.payment_status', 'paid');

        $this->assertSame(96, (int) MedicineBatch::withoutGlobalScopes()->sum('quantity'));
        $invoice = Invoice::withoutGlobalScopes()->with('payments')->firstOrFail();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('telebirr', $invoice->payments->first()->method);
        $this->assertSame('TB-778', $invoice->payments->first()->reference);

        // The pharmacist sees it is paid, so the medicine can be handed over; it cannot be paid twice
        $this->actingAs($pharmacist)->getJson('/api/pos/counter-sales?status=paid')->assertJsonPath('data.0.id', $order['id']);
        $this->actingAs($cashier)->postJson("/api/pos/counter-sales/{$order['id']}/pay", ['method' => 'cash'])->assertUnprocessable();
    }

    public function test_cancelling_offline_sales_and_pharmacies_without_the_rule(): void
    {
        $pharmacy = $this->tenant('pharmacy', ['settings' => ['pay_at_cashier' => true]]);
        $pharmacist = $this->userWithRole(User::ROLE_STAFF, $pharmacy);
        $listing = $this->listing($pharmacy, batches: [[100, 365]]);
        $sale = fn (array $extra = []) => $this->actingAs($pharmacist)->postJson('/api/pos/sales', [
            'items' => [['pharmacy_medicine_id' => $listing->id, 'quantity' => 2]], 'payment_method' => 'cash',
        ] + $extra)->assertCreated()->json('data');

        // The customer leaves before paying
        $id = $sale()['id'];
        $this->actingAs($pharmacist)->postJson("/api/pos/counter-sales/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        // A pharmacist cannot take the payment themselves
        $other = $sale()['id'];
        $this->actingAs($this->userWithRole(User::ROLE_NURSE, $pharmacy))->postJson("/api/pos/counter-sales/{$other}/pay", ['method' => 'cash'])->assertForbidden();

        // A sale recorded while offline was paid at the counter
        $this->assertSame('completed', $sale(['recorded_offline' => true])['status']);

        // Pharmacies that did not switch it on sell exactly as before
        $plain = $this->tenant('pharmacy');
        $listing2 = $this->listing($plain, batches: [[10, 365]]);
        $this->actingAs($this->userWithRole(User::ROLE_STAFF, $plain))->postJson('/api/pos/sales', [
            'items' => [['pharmacy_medicine_id' => $listing2->id, 'quantity' => 1]], 'payment_method' => 'cash',
        ])->assertCreated()->assertJsonPath('data.status', 'completed');
        $this->assertSame(2, Order::withoutGlobalScopes()->where('status', 'completed')->count());
    }
}
