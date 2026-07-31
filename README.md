# Zamaan Seafood Management ERP

Batch-level seafood ERP built on **Laravel 11**, **MySQL**, **Filament 3**, **Spatie Permission**, and **Laravel Sanctum**.

## Features

- Inventory with batch FEFO allocation and audited stock movements
- Purchase orders with receive → batch + `purchase_in` flow
- Tiered / override pricing resolution
- Two order channels: **POS** (fullscreen terminal) and **Sales Order** (restaurant / retailer credit)
- Customer types: Household, Restaurant, Retailer
- Auto-invoice on confirm, payments, overdue marking
- Deliveries for credit orders
- Wastage recording
- Dashboard widgets + CSV/PDF report exports
- Versioned REST API (`/api/v1`)

## Requirements

- PHP 8.2+, Composer
- MySQL 8+
- Node optional (Filament assets are published)

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Configure `.env`:

```env
APP_URL=http://127.0.0.1:8000
DB_DATABASE=zamaan_erp
DB_USERNAME=root
DB_PASSWORD=
```

```bash
php artisan migrate --seed
php artisan serve
```

Admin panel: [http://127.0.0.1:8000/admin/login](http://127.0.0.1:8000/admin/login)

| User | Email | Password | Role |
|------|-------|----------|------|
| Admin | admin@zamaanerp.com | password | admin |
| Warehouse | (demo seeder) | password | warehouse_staff |
| Sales | (demo seeder) | password | sales_staff |
| Delivery | (demo seeder) | password | delivery_staff |

Prefer `php artisan serve` over XAMPP subdirectory URLs — Livewire POSTs break under `/ZamaanErp/public`.

## Roles (panel access)

| Area | admin | warehouse_staff | sales_staff | delivery_staff |
|------|:-----:|:---------------:|:-----------:|:--------------:|
| Products / Batches / Suppliers / POs / Wastage | ✓ | ✓ | products only | |
| Customers / Pricing / POS / Sales Orders / Invoices / Reports | ✓ | | ✓ | |
| Deliveries | ✓ | | ✓ | ✓ |

## API (`/api/v1`)

Authenticate:

```http
POST /api/v1/auth/login
{ "email": "...", "password": "...", "device_name": "pos" }
```

Use `Authorization: Bearer {token}`.

| Method | Path | Roles |
|--------|------|-------|
| GET | `/products`, `/products/{id}` | any staff role |
| GET | `/customers`, `/customers/{id}` | admin, sales_staff |
| GET | `/stock/{product}` | admin, warehouse, sales |
| POST | `/sales-orders` | admin, sales_staff |
| GET | `/sales-orders/{id}` | admin, sales_staff |
| POST | `/invoices/{id}/payments` | admin, sales_staff |

Unit prices are always resolved server-side (client `unit_price` is ignored).

## Scheduled jobs

```bash
php artisan invoices:mark-overdue
```

Scheduled daily via `routes/console.php` — run a scheduler (`php artisan schedule:work` or cron).

## Tests

```bash
php artisan test
```

## Architecture notes

- All stock changes go through `StockService` (purchase_in, sale_out, wastage_out, adjustment)
- Confirm uses `StockAllocationService` (FEFO, skip expired, row locks) then invoice + optional delivery
- Cancel restores stock via adjustment when no payments exist
- Credit limit is enforced on confirm for non-retail channels
