<?php

namespace Database\Seeders;

use App\Enums\CustomerType;
use App\Enums\DeliveryStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesChannel;
use App\Enums\SalesOrderStatus;
use App\Enums\StorageLocation;
use App\Enums\SupplierType;
use App\Enums\UnitType;
use App\Enums\WastageReason;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerPriceOverride;
use App\Models\Delivery;
use App\Models\PriceListItem;
use App\Models\PricingTier;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ConfirmSalesOrderService;
use App\Services\ReceivePurchaseOrderService;
use App\Services\WastageService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@zamaanerp.com')->firstOrFail();

        $warehouse = User::firstOrCreate(
            ['email' => 'warehouse@zamaanerp.com'],
            ['name' => 'Amina Warehouse', 'password' => Hash::make('password')]
        );
        $warehouse->assignRole('warehouse_staff');

        $sales = User::firstOrCreate(
            ['email' => 'sales@zamaanerp.com'],
            ['name' => 'Hassan Sales', 'password' => Hash::make('password')]
        );
        $sales->assignRole('sales_staff');

        $driver = User::firstOrCreate(
            ['email' => 'delivery@zamaanerp.com'],
            ['name' => 'Omar Driver', 'password' => Hash::make('password')]
        );
        $driver->assignRole('delivery_staff');

        // --- Suppliers ---
        $fisherman = Supplier::firstOrCreate(
            ['name' => 'Ahmed Coastal Fisherman'],
            [
                'type' => SupplierType::Fisherman,
                'contact_phone' => '+252 61 111 1001',
                'contact_email' => 'ahmed.fish@example.com',
                'address' => 'Lido Beach Landing, Mogadishu',
                'notes' => 'Fresh daily catch — Hilib Case, Tuna, Bayaad, and more.',
            ]
        );

        $company = Supplier::firstOrCreate(
            ['name' => 'Horn of Africa Seafood Co'],
            [
                'type' => SupplierType::Company,
                'contact_phone' => '+252 61 222 2002',
                'contact_email' => 'orders@hoaseafood.com',
                'address' => 'Port Industrial Zone, Berbera',
                'notes' => 'Bulk chilled and frozen supply.',
            ]
        );

        $importer = Supplier::firstOrCreate(
            ['name' => 'Indian Ocean Imports'],
            [
                'type' => SupplierType::Import,
                'contact_phone' => '+971 50 333 3003',
                'contact_email' => 'export@ioimports.ae',
                'address' => 'Dubai Seafood Market Gate 4',
                'notes' => 'Imported Tamad, Laqam, Garam.',
            ]
        );

        // --- Products ---
        // Keys are stable legacy SKUs so re-seeding updates existing demo rows.
        $products = [
            'FISH-TUN-001' => ['name' => 'Hilib Case', 'species' => 'Hilib Case', 'sku' => 'FISH-HCS-001'],
            'FISH-KIN-001' => ['name' => 'Tuna', 'species' => 'Tuna', 'sku' => 'FISH-TUN-001'],
            'FISH-SNP-001' => ['name' => 'Bayaad', 'species' => 'Bayaad', 'sku' => 'FISH-BAY-001'],
            'SHL-LOB-001' => ['name' => 'Mix', 'species' => 'Mix', 'sku' => 'FISH-MIX-001'],
            'SHL-PRW-001' => ['name' => 'Hilib Cade', 'species' => 'Hilib Cade', 'sku' => 'FISH-HCD-001'],
            'FISH-SAL-001' => ['name' => 'Gaxash', 'species' => 'Gaxash', 'sku' => 'FISH-GAX-001'],
            'CEP-SQD-001' => ['name' => 'Sakhlad', 'species' => 'Sakhlad', 'sku' => 'FISH-SAK-001'],
            'BOX-MIX-001' => ['name' => 'Tamad', 'species' => 'Tamad', 'sku' => 'FISH-TAM-001'],
            'SHL-CRB-001' => ['name' => 'Laqam', 'species' => 'Laqam', 'sku' => 'FISH-LAQ-001'],
            'SPC-SHK-001' => ['name' => 'Garam', 'species' => 'Garam', 'sku' => 'FISH-GAR-001'],
        ];

        $productModels = [];
        foreach ($products as $legacySku => $p) {
            $product = Product::query()->where('sku', $p['sku'])->first()
                ?? Product::query()->where('sku', $legacySku)->first();

            $attributes = [
                'name' => $p['name'],
                'species' => $p['species'],
                'category' => 'Fish',
                'unit_type' => UnitType::WeightKg,
                'sku' => $p['sku'],
                'description' => "Premium {$p['name']} for retail and HORECA.",
            ];

            if ($product) {
                $product->update($attributes);
            } else {
                $product = Product::create($attributes);
            }

            $productModels[$p['sku']] = $product->fresh();
        }

        // Refresh supplier notes for renamed product set
        $fisherman->update(['notes' => 'Fresh daily catch — Hilib Case, Tuna, Bayaad, and more.']);
        $importer->update(['notes' => 'Imported Tamad, Laqam, Garam.']);

        // --- Pricing tiers ---
        $retailTier = PricingTier::firstOrCreate(
            ['name' => 'Household Standard'],
            ['customer_type' => CustomerType::Household]
        );
        $restaurantTier = PricingTier::firstOrCreate(
            ['name' => 'Restaurant Standard'],
            ['customer_type' => CustomerType::Restaurant]
        );
        $wholesaleTier = PricingTier::firstOrCreate(
            ['name' => 'Retailer Contract'],
            ['customer_type' => CustomerType::Retailer]
        );

        $sellPrice = 50000;
        $unitCost = 30000;

        // Flat Slsh pricing for all demo products / tiers
        foreach (array_keys($productModels) as $sku) {
            $productId = $productModels[$sku]->id;

            foreach (
                [
                    [$retailTier->id, 0],
                    [$restaurantTier->id, 0],
                    [$wholesaleTier->id, 0],
                    [$wholesaleTier->id, 50],
                    [$wholesaleTier->id, 100],
                ] as [$tierId, $minQty]
            ) {
                PriceListItem::updateOrCreate(
                    [
                        'pricing_tier_id' => $tierId,
                        'product_id' => $productId,
                        'min_quantity' => $minQty,
                    ],
                    ['price_per_unit' => $sellPrice]
                );
            }
        }

        // --- Customers ---
        $walkIn = Customer::firstOrCreate(
            ['name' => 'Walk-in Household Customer', 'type' => CustomerType::Household],
            [
                'contact_phone' => '+252 61 400 0001',
                'address' => 'Cash counter',
                'pricing_tier_id' => $retailTier->id,
                'payment_terms_days' => 0,
            ]
        );

        $fatima = Customer::firstOrCreate(
            ['name' => 'Fatima Ali', 'type' => CustomerType::Household],
            [
                'contact_phone' => '+252 61 400 0002',
                'contact_email' => 'fatima@example.com',
                'address' => 'Hodan District, Mogadishu',
                'pricing_tier_id' => $retailTier->id,
                'payment_terms_days' => 0,
            ]
        );

        $oceanGrill = Customer::firstOrCreate(
            ['name' => 'Ocean Grill Restaurant', 'type' => CustomerType::Restaurant],
            [
                'contact_phone' => '+252 61 500 0001',
                'contact_email' => 'orders@oceangrill.so',
                'address' => 'Lido Corniche, Mogadishu',
                'pricing_tier_id' => $restaurantTier->id,
                'credit_limit' => 5_000_000,
                'payment_terms_days' => 14,
            ]
        );

        $pearl = Customer::firstOrCreate(
            ['name' => 'Pearl Seafood Restaurant', 'type' => CustomerType::Restaurant],
            [
                'contact_phone' => '+252 61 500 0002',
                'contact_email' => 'chef@pearl.so',
                'address' => 'Airport Road, Mogadishu',
                'pricing_tier_id' => $restaurantTier->id,
                'credit_limit' => 3_000_000,
                'payment_terms_days' => 7,
            ]
        );

        $bulkBuyer = Customer::firstOrCreate(
            ['name' => 'Red Sea Retail Traders', 'type' => CustomerType::Retailer],
            [
                'contact_phone' => '+252 63 600 0001',
                'contact_email' => 'buying@redseatrade.so',
                'address' => 'Hargeisa Cold Store Complex',
                'pricing_tier_id' => $wholesaleTier->id,
                'credit_limit' => 25_000_000,
                'payment_terms_days' => 30,
            ]
        );

        $cityMart = Customer::firstOrCreate(
            ['name' => 'City Mart Distributors', 'type' => CustomerType::Retailer],
            [
                'contact_phone' => '+252 61 600 0002',
                'contact_email' => 'procurement@citymart.so',
                'address' => 'Bakaaro Wholesale Market',
                'pricing_tier_id' => $wholesaleTier->id,
                'credit_limit' => 15_000_000,
                'payment_terms_days' => 21,
            ]
        );

        // Keep existing demo customer credit limits on Slsh scale
        Customer::whereIn('name', [
            'Ocean Grill Restaurant',
            'Pearl Seafood Restaurant',
            'Red Sea Retail Traders',
            'City Mart Distributors',
        ])->each(function (Customer $customer) {
            $limits = [
                'Ocean Grill Restaurant' => 5_000_000,
                'Pearl Seafood Restaurant' => 3_000_000,
                'Red Sea Retail Traders' => 25_000_000,
                'City Mart Distributors' => 15_000_000,
            ];
            $customer->update(['credit_limit' => $limits[$customer->name]]);
        });

        // Negotiated restaurant override on Mix (same sell price in Slsh)
        CustomerPriceOverride::updateOrCreate(
            [
                'customer_id' => $oceanGrill->id,
                'product_id' => $productModels['FISH-MIX-001']->id,
            ],
            ['price_per_unit' => $sellPrice]
        );

        // --- Purchase order + receive (creates batches via service) ---
        if (! PurchaseOrder::where('supplier_id', $company->id)->exists()) {
            $po = PurchaseOrder::create([
                'supplier_id' => $company->id,
                'order_date' => now()->subDays(5)->toDateString(),
                'status' => PurchaseOrderStatus::Pending,
                'total_cost' => 0,
            ]);

            $poLines = [
                ['sku' => 'FISH-HCS-001', 'qty' => 120, 'cost' => $unitCost],
                ['sku' => 'FISH-TUN-001', 'qty' => 80, 'cost' => $unitCost],
                ['sku' => 'FISH-MIX-001', 'qty' => 40, 'cost' => $unitCost],
                ['sku' => 'FISH-HCD-001', 'qty' => 60, 'cost' => $unitCost],
                ['sku' => 'FISH-SAK-001', 'qty' => 50, 'cost' => $unitCost],
                ['sku' => 'FISH-TAM-001', 'qty' => 25, 'cost' => $unitCost],
            ];

            $total = 0;
            $lineDetails = [];

            foreach ($poLines as $i => $line) {
                $pol = PurchaseOrderLine::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $productModels[$line['sku']]->id,
                    'quantity' => $line['qty'],
                    'unit_cost' => $line['cost'],
                ]);
                $total += $line['qty'] * $line['cost'];

                // Stagger expiries so FEFO + aging widgets have variety
                $expiryOffset = [2, 5, 12, 25, 45, 60][$i] ?? 30;

                $lineDetails[$pol->id] = [
                    'expiry_date' => now()->addDays($expiryOffset)->toDateString(),
                    'catch_date' => now()->subDays(3)->toDateString(),
                    'production_date' => now()->subDays(2)->toDateString(),
                    'storage_location' => $i < 2
                        ? StorageLocation::Fresh->value
                        : ($i < 4 ? StorageLocation::Chilled->value : StorageLocation::Frozen->value),
                ];
            }

            $po->update(['total_cost' => $total]);

            app(ReceivePurchaseOrderService::class)->receive($po, $lineDetails, $admin->id);
        }

        // Extra near-expiry / imported batches (via StockService for audit trail)
        if (! Batch::where('batch_code', 'like', 'BCH-DEMO-%')->exists()) {
            $stock = app(\App\Services\StockService::class);

            $demoBatches = [
                [
                    'product_id' => $productModels['FISH-GAX-001']->id,
                    'supplier_id' => $importer->id,
                    'batch_code' => 'BCH-DEMO-GAXASH01',
                    'catch_date' => now()->subDays(10)->toDateString(),
                    'production_date' => now()->subDays(8)->toDateString(),
                    'expiry_date' => now()->addDays(1)->toDateString(),
                    'qty' => 30,
                    'storage_location' => StorageLocation::Frozen,
                    'unit_cost' => $unitCost,
                ],
                [
                    'product_id' => $productModels['FISH-BAY-001']->id,
                    'supplier_id' => $fisherman->id,
                    'batch_code' => 'BCH-DEMO-BAYAAD01',
                    'catch_date' => now()->subDay()->toDateString(),
                    'production_date' => now()->toDateString(),
                    'expiry_date' => now()->addDays(3)->toDateString(),
                    'qty' => 45,
                    'storage_location' => StorageLocation::Fresh,
                    'unit_cost' => $unitCost,
                ],
                [
                    'product_id' => $productModels['FISH-LAQ-001']->id,
                    'supplier_id' => $fisherman->id,
                    'batch_code' => 'BCH-DEMO-LAQAM01',
                    'catch_date' => now()->subDays(2)->toDateString(),
                    'production_date' => now()->subDay()->toDateString(),
                    'expiry_date' => now()->addDays(7)->toDateString(),
                    'qty' => 100,
                    'storage_location' => StorageLocation::Chilled,
                    'unit_cost' => $unitCost,
                ],
                [
                    'product_id' => $productModels['FISH-GAR-001']->id,
                    'supplier_id' => $importer->id,
                    'batch_code' => 'BCH-DEMO-GARAM01',
                    'catch_date' => now()->subDays(20)->toDateString(),
                    'production_date' => now()->subDays(15)->toDateString(),
                    'expiry_date' => now()->addDays(90)->toDateString(),
                    'qty' => 500,
                    'storage_location' => StorageLocation::Frozen,
                    'unit_cost' => $unitCost,
                ],
            ];

            foreach ($demoBatches as $demo) {
                $qty = $demo['qty'];
                unset($demo['qty']);
                $batch = Batch::create([
                    ...$demo,
                    'quantity_received' => $qty,
                    'quantity_available' => 0,
                ]);
                $stock->recordIn($batch, $qty, reason: 'Demo stock seed');
            }
        } else {
            // Keep existing demo batch codes in sync with renamed products
            $batchCodeMap = [
                'BCH-DEMO-SALMON01' => 'BCH-DEMO-GAXASH01',
                'BCH-DEMO-SNAP01' => 'BCH-DEMO-BAYAAD01',
                'BCH-DEMO-CRAB01' => 'BCH-DEMO-LAQAM01',
                'BCH-DEMO-SPEC01' => 'BCH-DEMO-GARAM01',
            ];
            foreach ($batchCodeMap as $from => $to) {
                Batch::where('batch_code', $from)->update(['batch_code' => $to]);
            }
        }

        // Sync existing demo costs/sell prices to Slsh
        Batch::query()->update(['unit_cost' => $unitCost]);
        PurchaseOrderLine::query()->update(['unit_cost' => $unitCost]);
        PurchaseOrder::query()->with('lines')->each(function (PurchaseOrder $po) {
            $po->update([
                'total_cost' => round($po->lines->sum(fn ($l) => (float) $l->quantity * (float) $l->unit_cost), 2),
            ]);
        });
        CustomerPriceOverride::query()->update(['price_per_unit' => $sellPrice]);

        // Record a bit of wastage for dashboard stats
        $nearExpiryBatch = Batch::whereIn('batch_code', ['BCH-DEMO-GAXASH01', 'BCH-DEMO-SALMON01'])->first();
        if ($nearExpiryBatch && $nearExpiryBatch->quantity_available >= 30) {
            app(WastageService::class)->record(
                $nearExpiryBatch,
                2,
                WastageReason::Expired,
                'Demo wastage — soft texture',
                $warehouse->id
            );
        }

        // --- Sales orders via confirm service ---
        $confirm = app(ConfirmSalesOrderService::class);

        try {
            if (! SalesOrder::where('channel', SalesChannel::Pos)->exists()) {
                $retailOrder = SalesOrder::create([
                    'customer_id' => $walkIn->id,
                    'channel' => SalesChannel::Pos,
                    'order_date' => now()->toDateString(),
                    'status' => SalesOrderStatus::Draft,
                    'delivery_required' => false,
                    'created_by' => $sales->id,
                ]);
                SalesOrderLine::create([
                    'sales_order_id' => $retailOrder->id,
                    'product_id' => $productModels['FISH-HCS-001']->id,
                    'quantity' => 3,
                    'unit_price' => $sellPrice,
                    'subtotal' => 3 * $sellPrice,
                ]);
                SalesOrderLine::create([
                    'sales_order_id' => $retailOrder->id,
                    'product_id' => $productModels['FISH-LAQ-001']->id,
                    'quantity' => 4,
                    'unit_price' => $sellPrice,
                    'subtotal' => 4 * $sellPrice,
                ]);
                $confirm->confirm($retailOrder, PaymentMethod::Cash);
            }

            if (! SalesOrder::where('channel', SalesChannel::SalesOrder)->exists()) {
                $restOrder = SalesOrder::create([
                    'customer_id' => $oceanGrill->id,
                    'channel' => SalesChannel::SalesOrder,
                    'order_date' => now()->subDay()->toDateString(),
                    'status' => SalesOrderStatus::Draft,
                    'delivery_required' => true,
                    'delivery_date' => now()->toDateString(),
                    'created_by' => $sales->id,
                ]);
                SalesOrderLine::create([
                    'sales_order_id' => $restOrder->id,
                    'product_id' => $productModels['FISH-MIX-001']->id,
                    'quantity' => 8,
                    'unit_price' => $sellPrice,
                    'subtotal' => 8 * $sellPrice,
                ]);
                SalesOrderLine::create([
                    'sales_order_id' => $restOrder->id,
                    'product_id' => $productModels['FISH-BAY-001']->id,
                    'quantity' => 12,
                    'unit_price' => $sellPrice,
                    'subtotal' => 12 * $sellPrice,
                ]);
                $restOrder = $confirm->confirm($restOrder);

                if ($restOrder->delivery) {
                    $restOrder->delivery->update([
                        'delivery_staff_id' => $driver->id,
                        'status' => DeliveryStatus::InTransit,
                        'notes' => 'Leave at kitchen loading bay.',
                    ]);
                }

                // Partial payment on restaurant invoice
                if ($restOrder->invoice) {
                    app(\App\Services\InvoiceService::class)->applyPayment(
                        $restOrder->invoice,
                        200_000,
                        PaymentMethod::Zaad,
                        now(),
                        $sales->id
                    );
                }
            }

            if (! SalesOrder::where('customer_id', $bulkBuyer->id)->exists()) {
                $whOrder = SalesOrder::create([
                    'customer_id' => $bulkBuyer->id,
                    'channel' => SalesChannel::SalesOrder,
                    'order_date' => now()->subDays(2)->toDateString(),
                    'status' => SalesOrderStatus::Draft,
                    'delivery_required' => true,
                    'delivery_date' => now()->addDay()->toDateString(),
                    'created_by' => $sales->id,
                ]);
                SalesOrderLine::create([
                    'sales_order_id' => $whOrder->id,
                    'product_id' => $productModels['FISH-HCS-001']->id,
                    'quantity' => 55, // hits qty break @50
                    'unit_price' => $sellPrice,
                    'subtotal' => 55 * $sellPrice,
                ]);
                SalesOrderLine::create([
                    'sales_order_id' => $whOrder->id,
                    'product_id' => $productModels['FISH-HCD-001']->id,
                    'quantity' => 40,
                    'unit_price' => $sellPrice,
                    'subtotal' => 40 * $sellPrice,
                ]);
                $whOrder = $confirm->confirm($whOrder);

                if ($whOrder->delivery) {
                    $whOrder->delivery->update([
                        'delivery_staff_id' => $driver->id,
                        'status' => DeliveryStatus::Pending,
                        'address' => $bulkBuyer->address,
                        'notes' => 'Cold truck required.',
                    ]);
                }
            }
        } catch (\RuntimeException $e) {
            // Product/batch renames already applied; skip demo sales if stock is short.
            $this->command?->warn('Skipped demo sales order seeding: '.$e->getMessage());
            SalesOrder::query()
                ->where('status', SalesOrderStatus::Draft)
                ->whereDoesntHave('invoice')
                ->delete();
        }

        $this->command?->info('Demo data seeded successfully.');
        $this->command?->table(
            ['Account', 'Email', 'Password'],
            [
                ['Admin', 'admin@zamaanerp.com', 'password'],
                ['Warehouse', 'warehouse@zamaanerp.com', 'password'],
                ['Sales', 'sales@zamaanerp.com', 'password'],
                ['Delivery', 'delivery@zamaanerp.com', 'password'],
            ]
        );
    }
}
