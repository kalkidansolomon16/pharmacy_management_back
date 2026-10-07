# MedLink Ethiopia — Pharmacy Management SaaS (API)

Multi-tenant pharmacy & prescription platform built for the Ethiopian market with **Laravel 12 + Sanctum**.
The Vue 3 SPA lives in `../pharmacy_managment_system/pharmacy_managment_system`.

Pharmacies manage inventory (batches, expiry, FEFO), sell at the counter and fulfil online orders.
Hospitals register patients and issue digital prescriptions. Patients search live stock and prices across
pharmacies, check their prescription, and order online. A platform admin verifies every organization's licence.

## Built for Ethiopia

| Area | What it means here |
| --- | --- |
| Money | All prices in **ETB (Birr)**, stored as `DECIMAL(12,2)` |
| Phones | Ethio telecom `09…` and Safaricom `07…` validated and normalised to `+2519…`; landlines allowed for organizations |
| Places | 14 regions/city administrations, major cities, Addis Ababa's 11 sub-cities, woreda (`config/ethiopia.php`) |
| Licensing | Pharmacies register with an **EFDA licence**, hospitals with an **MoH licence**, optional 10-digit **TIN**; nothing goes live until verified |
| Payments | Cash, **telebirr**, **CBE Birr**, Chapa, bank transfer, **CBHI/EHIS insurance** |
| Supply | Batches record the supplier (EPSS, EPHARM, Cadila Ethiopia…) |
| Catalogue | 57 medicines from the Ethiopian Essential Medicines List, with Amharic category names |
| Calendar & time | `Africa/Addis_Ababa` timezone; the SPA shows Ethiopian-calendar (E.C.) dates and Amharic UI |
| Controlled drugs | Narcotics/psychotropics can only be dispensed in person, never ordered online |

## Quick start (Windows + XAMPP)

Requirements: PHP 8.2+, Composer, MySQL/MariaDB (XAMPP), Node 20.19+ for the frontend.

1. Start MySQL from the XAMPP control panel and create the database:
   ```sql
   CREATE DATABASE pharmacy_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
2. Configure and seed:
   ```bash
   cp .env.example .env        # then set DB_PORT (this machine's XAMPP MariaDB listens on 3307)
   composer install
   php artisan key:generate
   php artisan migrate:fresh --seed
   ```
3. Run the API, the queue worker (notifications, low-stock checks) and the scheduler (daily 06:00 expiry scan):
   ```bash
   php artisan serve            # http://127.0.0.1:8000
   php artisan queue:work       # in a second terminal
   php artisan schedule:work    # in a third terminal (or: composer dev, which runs all three)
   ```
4. Start the SPA (see its README): `npm install && npm run dev` → http://localhost:5173

Run the daily housekeeping manually any time: `php artisan pharmacy:scan --sync`.

### Demo accounts

Every seeded account uses the password **`Password@123`**.

| Role | Email | Lands on |
| --- | --- | --- |
| Platform admin | `admin@medlink.et` | Platform dashboard, approvals, catalogue |
| Pharmacy admin | `bole.admin@medlink.et` | Bole Medhanit Pharmacy (full catalogue, 30 days of sales) |
| Pharmacist | `bole.pharmacist@medlink.et` | POS, orders, inventory (read) |
| Hospital admin | `entoto.admin@medlink.et` | Entoto Hills General Hospital |
| Doctor | `dr.hana@medlink.et` / `dr.dawit@medlink.et` | Patients, prescriptions |
| Customer | `abebe@example.com` (has a prescription), `meron@example.com`, `selam@example.com` | Search, cart, orders |
| Pending pharmacy | `gondar.admin@medlink.et` | "Awaiting verification" screen |

Other pharmacies: `piassa.`, `kazanchis.`, `megenagna.`, `adama.`, `hawassa.`, `bahir.` + `admin@` / `pharmacist@medlink.et`.

## Architecture

```
app/
├── Support/TenantContext.php        current tenant (follows the signed-in user; jobs can pin one)
├── Models/Scopes/TenantScope.php    global scope: where tenant_id = current tenant
├── Models/Concerns/BelongsToTenant  adds the scope + stamps tenant_id on create
├── Models/Concerns/LogsActivity     created/updated/deleted -> activity_logs (with old/new values)
├── Policies/                        one per model; tenant ownership re-checked (defence in depth)
├── Http/Requests/                   validation + authorize() through policies (403 before 422)
├── Http/Resources/                  every response shape
├── Http/Controllers/                thin: authorize -> call a service -> return a resource
├── Services/
│   ├── Inventory/StockService       receive, adjust, FEFO allocate/deduct, expire batches
│   ├── Inventory/InventoryService   listings, alerts
│   ├── Orders/OrderService          online orders, walk-in sales, lifecycle, partial fulfilment
│   ├── Prescriptions/…Service       issue, verify, dispense accounting, cancel, expire
│   ├── Reports/                     dashboards, sales/inventory/prescription reports, CSV/PDF export
│   └── ActivityLogger, Auth/RegistrationService, Users/TeamService, Catalog/PublicSearchService
├── Jobs/                            CheckLowStock, ScanPharmacyInventory (queued)
├── Notifications/                   queued, stored with tenant_id (custom TenantDatabaseChannel)
└── Console/Commands/ScanInventory   `pharmacy:scan`, scheduled daily at 06:00 Addis Ababa
```

### Multi-tenancy
Tenant-owned models (`PharmacyMedicine`, `MedicineBatch`, `StockMovement`, `Order`, `Patient`, `Prescription`, `ActivityLog`)
use `BelongsToTenant`. Queries — including route-model binding — are automatically limited to the user's tenant,
so `/api/inventory/{id}` of another pharmacy is a **404**, not a leak. Cross-tenant reads are explicit and rare
(`Prescription::acrossTenants()` when a pharmacy dispenses a hospital's prescription). Public endpoints run with no tenant
and filter on `is_public` + active pharmacies. Super admins have no tenant and see everything.

### Roles & permissions
`spatie/laravel-permission`, matrix in `app/Support/Permissions.php`:
`super_admin`, `pharmacy_admin`, `staff` (pharmacist), `hospital_admin`, `doctor`, `customer`.
Enforced in three layers: route middleware (`tenant.type:pharmacy`, `role:super_admin`, `active`), form-request
`authorize()` via policies, and policies again in controllers. The SPA receives the permission list at login to
render role-based UI — the backend never trusts it.

### Business rules
* **FEFO** — `StockService::allocate()` locks sellable batches ordered by expiry date and takes from the earliest first;
  each order line records exactly which batches supplied it (`order_item_batches`).
* **No expired medicine is ever sold** — "sellable" means `quantity > 0 AND expiry_date > today`; a batch expiring today
  is already excluded. Expired batches cannot be received. The daily scan writes them off with an `expired` movement.
* **Prescription quantity enforced** — online orders verify the code *and* the patient's phone; quantity can never
  exceed `total - dispensed`, re-checked with row locks at dispense time, across all pharmacies.
* **Partial fulfilment** — completing with `allow_partial` dispenses what is in stock, charges only for that, marks the
  order `partially_completed` and the prescription `partially_dispensed`.
* **Order lifecycle** — `Order::TRANSITIONS` state machine (`pending → confirmed → ready → completed`, plus
  `partially_completed`, `cancelled`, `rejected`); illegal moves are 422.
* **Transactions** everywhere stock or prescriptions change (`DB::transaction` + `lockForUpdate`).

### API conventions
* Base URL `/api`, Bearer tokens (Sanctum).
* Reads return resources: `{ data }` or `{ data, links, meta }` when paginated.
* Actions return `{ message, data }`.
* Errors always `{ message, errors? }`: 401 unauthenticated, 403 forbidden (`code: tenant_pending` etc.), 404, 405,
  422 validation *and* business-rule violations (`BusinessRuleException`), 429 rate limited.
* Rate limits: login/register 5/min per email, public 60/min, prescription verification 10/min, API 120/min per user.

### Main endpoints

| Area | Endpoints |
| --- | --- |
| Public | `GET public/meta, stats, categories, medicines?q&city&sub_city&min_price&max_price&rx&sort, pharmacies, pharmacies/{slug}, pharmacies/{slug}/medicines` · `POST public/prescriptions/verify` |
| Auth | `POST auth/login, auth/register, auth/register-organization, auth/logout` · `GET auth/me` · `PUT auth/profile, auth/password` |
| Customer | `GET/POST my/orders` · `GET my/orders/{id}` · `POST my/orders/{id}/cancel` |
| Pharmacy | `inventory` (CRUD) · `inventory/alerts` · `POST inventory/scan` · `POST inventory/{id}/batches` · `GET batches` · `POST batches/{id}/adjust` · `GET stock-movements` · `orders` + `confirm/ready/complete/reject/cancel` · `GET pos/products` · `POST pos/sales` · `GET pos/prescriptions/{code}` |
| Hospital | `patients` (CRUD) · `GET/POST prescriptions` · `GET prescriptions/{id}` · `POST prescriptions/{id}/cancel` |
| Shared | `GET dashboard` · `notifications` (+ read, read-all, unread-count) · `users` · `organization` · `activity-logs` · `reports/{sales,inventory,prescriptions}` · `reports/{type}/export?format=csv\|pdf` |
| Platform | `medicines`, `categories` (write = super admin) · `admin/tenants` · `PATCH admin/tenants/{id}/status` |

## Tests

```bash
php artisan test
```
36 feature/unit tests (SQLite in memory) cover FEFO deduction order, expired stock never sold, same-day expiry,
prescription quantity across orders, phone verification, expired prescriptions, partial fulfilment billing,
order state machine, tenant isolation (inventory, POS, team, audit log), role boundaries, pending-organization
lock-out and approval, public search visibility, response shapes and Ethiopian phone rules.

## Milestone checklist

| Requirement | Where |
| --- | --- |
| Thin controllers, logic in services | `app/Http/Controllers/*`, `app/Services/*` |
| Tenant isolation enforced globally | `TenantScope`, `BelongsToTenant`, `TenantIsolationTest` |
| Policies, not inline checks | `app/Policies/*`, form-request `authorize()`, report gates in `AppServiceProvider` |
| Validation | `app/Http/Requests/*`, `EthiopianPhone`, `UniquePhone` rules |
| FEFO / no expired sales / Rx quantity / transactions | `StockService`, `OrderService`, `PrescriptionService` + tests |
| RESTful, consistent responses, proper status codes | `routes/api.php`, Resources, `bootstrap/app.php` exception rendering |
| Audit logs | `LogsActivity`, `ActivityLogger`, `GET activity-logs` |
| Notifications: low stock, expiry, order status | `app/Notifications/*`, `app/Jobs/*` |
| Reports + Excel/PDF export | `ReportService`, `ReportExporter`, `resources/views/reports/table.blade.php` |
| Background jobs, scheduler, rate limiting | queued notifications/jobs, `routes/console.php`, `AppServiceProvider` |
| Automated tests | `tests/Feature`, `tests/Unit` |

## Notes

* The pre-rebuild code (old models, controllers, migrations and an uncommitted-changes patch) was moved, not deleted, to
  `../_legacy_backup/`.
* `santigarcor/laratrust` was removed; the project standardises on `spatie/laravel-permission`.
