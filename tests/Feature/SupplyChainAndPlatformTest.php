<?php

namespace Tests\Feature;

use App\Models\MedicineBatch;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\StockService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplyChainAndPlatformTest extends TestCase
{
    private Tenant $pharmacy;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pharmacy = $this->tenant('pharmacy', ['tin_number' => '1000234560']);
        $this->admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $this->pharmacy);
    }

    private function store(string $code, bool $default = false): Store
    {
        return Store::withoutGlobalScopes()->create([
            'tenant_id' => $this->pharmacy->id, 'name' => "Store {$code}", 'code' => $code,
            'type' => $default ? 'dispensary' : 'main', 'is_dispensing' => $default, 'is_default' => $default,
        ]);
    }

    public function test_transfers_pick_the_earliest_expiry_from_the_source_store(): void
    {
        $main = $this->store('MAIN');
        $opd = $this->store('OPD', true);
        $listing = $this->listing($this->pharmacy, batches: []);
        Auth::setUser($this->admin);
        foreach ([['LATE', 400], ['EARLY', 60]] as [$batch, $days]) {
            app(StockService::class)->receive($listing, [
                'batch_number' => $batch, 'quantity' => 50, 'expiry_date' => today()->addDays($days)->toDateString(),
                'purchase_price' => 5, 'store_id' => $main->id,
            ]);
        }

        $transfer = $this->actingAs($this->admin)->postJson('/api/transfers', [
            'from_store_id' => $main->id, 'to_store_id' => $opd->id, 'items' => [['pharmacy_medicine_id' => $listing->id, 'quantity' => 60]],
        ])->assertCreated()->json('data');

        $this->actingAs($this->admin)->postJson("/api/transfers/{$transfer['id']}/dispatch")->assertOk()->assertJsonPath('data.status', 'dispatched');
        $this->actingAs($this->admin)->postJson("/api/transfers/{$transfer['id']}/receive")->assertOk()->assertJsonPath('data.status', 'received');

        $inOpd = MedicineBatch::withoutGlobalScopes()->where('store_id', $opd->id)->pluck('quantity', 'batch_number');
        $this->assertEquals(['EARLY' => 50, 'LATE' => 10], $inOpd->sortKeys()->all());
        $this->assertSame(40, (int) MedicineBatch::withoutGlobalScopes()->where('store_id', $main->id)->sum('quantity'));
    }

    public function test_goods_are_received_against_a_purchase_order_and_duplicate_batches_refused(): void
    {
        $listing = $this->listing($this->pharmacy, batches: []);
        $supplier = Supplier::withoutGlobalScopes()->create(['tenant_id' => $this->pharmacy->id, 'name' => 'EPSS', 'type' => 'epss', 'is_active' => true]);

        $po = $this->actingAs($this->admin)->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id, 'items' => [['pharmacy_medicine_id' => $listing->id, 'quantity' => 100, 'unit_cost' => 4.5]],
        ])->assertCreated()->json('data');
        $itemId = $po['items'][0]['id'];

        $line = ['item_id' => $itemId, 'quantity' => 60, 'batch_number' => 'EPSS-1', 'expiry_date' => today()->addYear()->toDateString(), 'gtin' => '06223000012345'];
        $this->actingAs($this->admin)->postJson("/api/purchase-orders/{$po['id']}/receive", ['lines' => [$line]])->assertStatus(422); // not sent yet
        $this->actingAs($this->admin)->postJson("/api/purchase-orders/{$po['id']}/send")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/purchase-orders/{$po['id']}/receive", ['lines' => [$line]])
            ->assertOk()->assertJsonPath('data.status', 'partially_received');
        $this->actingAs($this->admin)->postJson("/api/purchase-orders/{$po['id']}/receive", ['lines' => [['quantity' => 40] + $line]])
            ->assertStatus(422); // same batch number again
        $this->actingAs($this->admin)->postJson("/api/purchase-orders/{$po['id']}/receive", ['lines' => [['quantity' => 41, 'batch_number' => 'EPSS-2'] + $line]])
            ->assertStatus(422); // more than outstanding

        $batch = MedicineBatch::withoutGlobalScopes()->where('batch_number', 'EPSS-1')->firstOrFail();
        $this->assertSame(60, $batch->quantity);
        $this->assertSame($supplier->id, $batch->supplier_id);
        $this->assertSame('06223000012345', $batch->gtin);

        // The scanner resolves a GS1 DataMatrix to that batch
        $this->actingAs($this->admin)->getJson('/api/scan?code='.urlencode('0106223000012345'.'10EPSS-1'))->assertOk();
    }

    public function test_supplier_returns_take_stock_off_the_shelf(): void
    {
        $listing = $this->listing($this->pharmacy, batches: [[30, 200]]);
        $batch = MedicineBatch::withoutGlobalScopes()->where('pharmacy_medicine_id', $listing->id)->first();

        $this->actingAs($this->admin)->postJson('/api/supplier-returns', ['batch_id' => $batch->id, 'quantity' => 10, 'reason' => 'damaged'])
            ->assertCreated();
        $this->assertSame(20, $batch->fresh()->quantity);

        $this->actingAs($this->admin)->postJson('/api/supplier-returns', ['batch_id' => $batch->id, 'quantity' => 50, 'reason' => 'damaged'])
            ->assertStatus(422);
    }

    public function test_offline_sales_are_idempotent_by_client_uuid_and_get_an_invoice(): void
    {
        $listing = $this->listing($this->pharmacy, batches: [[50, 300]]);
        $staff = $this->userWithRole(User::ROLE_STAFF, $this->pharmacy);
        $payload = ['items' => [['pharmacy_medicine_id' => $listing->id, 'quantity' => 5]], 'payment_method' => 'mpesa', 'client_uuid' => (string) Str::uuid()];

        $first = $this->actingAs($staff)->postJson('/api/pos/sales', $payload)->assertSuccessful()->json('data.id');
        $again = $this->actingAs($staff)->postJson('/api/pos/sales', $payload)->assertSuccessful()->json('data.id');

        $this->assertSame($first, $again);
        $this->assertSame(1, Order::withoutGlobalScopes()->count());
        $this->assertSame(45, $listing->batches()->withoutGlobalScopes()->sum('quantity'));
        $this->assertNotNull(Order::withoutGlobalScopes()->find($first)->invoice);
    }

    public function test_a_lapsed_subscription_is_read_only(): void
    {
        $plan = Plan::create(['code' => 'pharmacy-pro', 'name' => 'Pro', 'price_monthly' => 3500, 'modules' => ['pharmacy'], 'is_active' => true]);
        $sub = Subscription::create(['tenant_id' => $this->pharmacy->id, 'plan_id' => $plan->id, 'status' => 'active',
            'current_period_start' => now()->subMonths(2), 'current_period_end' => now()->subDays(Subscription::GRACE_DAYS + 1)]);
        $listing = $this->listing($this->pharmacy);

        $this->actingAs($this->admin)->getJson('/api/inventory')->assertOk();
        $this->actingAs($this->admin)->postJson('/api/pos/sales', ['items' => [['pharmacy_medicine_id' => $listing->id, 'quantity' => 1]], 'payment_method' => 'cash'])
            ->assertStatus(402)->assertJsonPath('code', 'subscription_expired');
        $this->actingAs($this->admin)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.subscription.usable', false);

        $sub->update(['current_period_end' => now()->addMonth()]);
        $this->actingAs($this->admin->fresh())->postJson('/api/pos/sales', ['items' => [['pharmacy_medicine_id' => $listing->id, 'quantity' => 1]], 'payment_method' => 'cash'])
            ->assertSuccessful();
    }

    public function test_the_owner_sees_every_branch(): void
    {
        $branch = $this->tenant('pharmacy', ['parent_id' => $this->pharmacy->id, 'name' => 'Branch two']);
        $owner = $this->userWithRole(User::ROLE_OWNER, $this->pharmacy);

        $data = $this->actingAs($owner)->getJson('/api/owner/dashboard')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$this->pharmacy->id, $branch->id], collect($data['branches'])->pluck('id')->all());

        $this->actingAs($this->userWithRole(User::ROLE_STAFF, $this->pharmacy))->getJson('/api/owner/dashboard')->assertForbidden();
    }

    public function test_fhir_exposes_patients_only_to_their_hospital(): void
    {
        $hospital = $this->tenant('hospital');
        $patient = $this->patient($hospital, ['mrn' => 'EHG-000100']);
        $doctor = $this->userWithRole(User::ROLE_DOCTOR, $hospital);

        $this->actingAs($doctor)->getJson('/api/fhir/metadata')->assertOk()->assertJsonPath('resourceType', 'CapabilityStatement');
        $this->actingAs($doctor)->getJson('/api/fhir/Patient')->assertOk()
            ->assertJsonPath('resourceType', 'Bundle')->assertJsonPath('total', 1)
            ->assertJsonPath('entry.0.resource.resourceType', 'Patient');

        $other = $this->userWithRole(User::ROLE_DOCTOR, $this->tenant('hospital'));
        $this->actingAs($other)->getJson("/api/fhir/Patient/{$patient->id}")->assertNotFound();
    }

    public function test_integration_tokens_can_only_read_fhir(): void
    {
        $hospital = $this->tenant('hospital');
        $admin = $this->userWithRole(User::ROLE_HOSPITAL_ADMIN, $hospital);
        $token = $this->actingAs($admin)->postJson('/api/integrations/tokens', ['name' => 'DHIS2 bridge'])->assertCreated()->json('data.token');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/fhir/Patient')->assertOk();
        $this->withToken($token)->getJson('/api/patients')->assertForbidden();
    }
}
