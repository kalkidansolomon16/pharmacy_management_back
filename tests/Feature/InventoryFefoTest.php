<?php

namespace Tests\Feature;

use App\Jobs\ScanPharmacyInventory;
use App\Models\MedicineBatch;
use App\Models\StockMovement;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryFefoTest extends TestCase
{
    public function test_counter_sale_deducts_stock_first_expiry_first_out(): void
    {
        $pharmacy = $this->tenant();
        $staff = $this->userWithRole(User::ROLE_STAFF, $pharmacy);
        // Created in "wrong" order on purpose: the later-expiring batch has the lower id
        $listing = $this->listing($pharmacy, batches: [[50, 300], [30, 40], [20, 120]]);
        [$late, $early, $middle] = MedicineBatch::withoutGlobalScopes()->orderBy('id')->get();

        Sanctum::actingAs($staff);
        $this->postJson('/api/pos/sales', [
            'items' => [['pharmacy_medicine_id' => $listing->id, 'quantity' => 45]],
            'payment_method' => 'cash',
        ])->assertCreated()->assertJsonPath('data.status', 'completed');

        $this->assertSame(0, $early->fresh()->quantity, 'Earliest expiry is used first');
        $this->assertSame(5, $middle->fresh()->quantity, 'Then the next one');
        $this->assertSame(50, $late->fresh()->quantity, 'Latest expiry untouched');
        $this->assertSame(-45, (int) StockMovement::withoutGlobalScopes()->where('type', 'out')->sum('quantity'));
    }

    public function test_expired_stock_is_never_sold(): void
    {
        $pharmacy = $this->tenant();
        $staff = $this->userWithRole(User::ROLE_STAFF, $pharmacy);
        $listing = $this->listing($pharmacy, batches: [[100, -3], [10, 200]]); // 100 expired, 10 sellable

        Sanctum::actingAs($staff);
        $this->postJson('/api/pos/sales', [
            'items' => [['pharmacy_medicine_id' => $listing->id, 'quantity' => 20]],
            'payment_method' => 'cash',
        ])->assertUnprocessable()->assertJsonStructure(['message', 'errors']);

        $this->assertSame(100, MedicineBatch::withoutGlobalScopes()->where('quantity', 100)->value('quantity'), 'Expired batch untouched');

        $this->postJson('/api/pos/sales', [
            'items' => [['pharmacy_medicine_id' => $listing->id, 'quantity' => 10]],
            'payment_method' => 'telebirr',
        ])->assertCreated();
    }

    public function test_batch_expiring_today_is_not_sellable(): void
    {
        $pharmacy = $this->tenant();
        $listing = $this->listing($pharmacy, batches: [[10, 0]]);

        $this->assertSame(0, $listing->availableQuantity());
    }

    public function test_cannot_receive_already_expired_stock(): void
    {
        $pharmacy = $this->tenant();
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $pharmacy);
        $listing = $this->listing($pharmacy, batches: []);

        Sanctum::actingAs($admin);
        $this->postJson("/api/inventory/{$listing->id}/batches", [
            'batch_number' => 'X1', 'quantity' => 10, 'expiry_date' => today()->subDay()->toDateString(), 'purchase_price' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors('expiry_date');

        $this->postJson("/api/inventory/{$listing->id}/batches", [
            'batch_number' => 'X2', 'quantity' => 10, 'expiry_date' => today()->addYear()->toDateString(), 'purchase_price' => 5,
        ])->assertCreated();

        $this->assertDatabaseHas('stock_movements', ['type' => 'in', 'quantity' => 10]);
    }

    public function test_adjustment_cannot_make_stock_negative(): void
    {
        $pharmacy = $this->tenant();
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $pharmacy);
        $this->listing($pharmacy, batches: [[5, 100]]);
        $batch = MedicineBatch::withoutGlobalScopes()->first();

        Sanctum::actingAs($admin);
        $this->postJson("/api/batches/{$batch->id}/adjust", ['quantity' => -6, 'reason' => 'Damaged'])->assertUnprocessable();
        $this->postJson("/api/batches/{$batch->id}/adjust", ['quantity' => -2, 'reason' => 'Damaged'])->assertOk();

        $this->assertSame(3, $batch->fresh()->quantity);
    }

    public function test_daily_scan_writes_off_expired_batches(): void
    {
        $pharmacy = $this->tenant();
        $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $pharmacy);
        $this->listing($pharmacy, batches: [[25, -1], [10, 200]]);

        ScanPharmacyInventory::dispatchSync($pharmacy->id);

        $this->assertSame(0, MedicineBatch::withoutGlobalScopes()->where('batch_number', 'like', '%-0')->value('quantity'));
        $this->assertDatabaseHas('stock_movements', ['type' => 'expired', 'quantity' => -25]);
        $this->assertDatabaseHas('notifications', ['type' => 'App\\Notifications\\ExpiryAlert', 'tenant_id' => $pharmacy->id]);
    }
}
