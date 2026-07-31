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
                'notes' => 'Fresh daily catch — tuna, kingfish, lobster.',
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
                'notes' => 'Imported prawns, salmon, crab.',
            ]
        );

        // --- Products ---
        $products = [
            ['name' => 'Yellowfin Tuna', 'species' => 'Thunnus albacares', 'category' => 'Fish', 'unit_type' => UnitType::WeightKg, 'sku' => 'FISH-TUN-001'],
            ['name' => 'Kingfish', 'species' => 'Scomberomorus', 'category' => 'Fish', 'unit_type' => UnitType::WeightKg, 'sku' => 'FISH-KIN-001'],
            ['name' => 'Red Snapper', 'species' => 'Lutjanus', 'category' => 'Fish', 'unit_type' => UnitType::WeightKg, 'sku' => 'FISH-SNP-001'],
            ['name' => 'Lobster', 'species' => 'Panulirus', 'category' => 'Shellfish', 'unit_type' => UnitType::WeightKg, 'sku' => 'SHL-LOB-001'],
            ['name' => 'Tiger Prawns', 'species' => 'Penaeus monodon', 'category' => 'Shellfish', 'unit_type' => UnitType::WeightKg, 'sku' => 'SHL-PRW-001'],
            ['name' => 'Atlantic Salmon', 'species' => 'Salmo salar', 'category' => 'Fish', 'unit_type' => UnitType::WeightKg, 'sku' => 'FISH-SAL-001'],
            ['name' => 'Squid', 'species' => 'Loligo', 'category' => 'Cephalopod', 'unit_type' => UnitType::WeightKg, 'sku' => 'CEP-SQD-001'],
            ['name' => 'Mixed Fish Box', 'species' => 'Assorted', 'category' => 'Mixed', 'unit_type' => UnitType::Box, 'sku' => 'BOX-MIX-001'],
            ['name' => 'Crab (piece)', 'species' => 'Portunus', 'category' => 'Shellfish', 'unit_type' => UnitType::Piece, 'sku' => 'SHL-CRB-001'],
            ['name' => 'Dried Shark Fins (demo)', 'species' => 'Carcharhinus', 'category' => 'Specialty', 'unit_type' => UnitType::WeightG, 'sku' => 'SPC-SHK-001'],
        ];

        $productModels = [];
        foreach ($products as $p) {
            $productModels[$p['sku']] = Product::firstOrCreate(
                ['sku' => $p['sku']],
                [
                    'name' => $p['name'],
                    'species' => $p['species'],
                    'category' => $p['category'],
                    'unit_type' => $p['unit_type'],
                    'description' => "Premium {$p['name']} for retail and HORECA.",
                ]
            );
        }

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

        // price maps: retail / restaurant / wholesale base / wholesale break@50 / wholesale break@100
        $prices = [
            'FISH-TUN-001' => [18, 15, 12, 10, 8],
            'FISH-KIN-001' => [16, 13, 11, 9, 7.5],
            'FISH-SNP-001' => [20, 17, 14, 12, 10],
            'SHL-LOB-001' => [35, 30, 26, 22, 19],
            'SHL-PRW-001' => [22, 18, 15, 13, 11],
            'FISH-SAL-001' => [28, 24, 20, 17, 15],
            'CEP-SQD-001' => [12, 10, 8, 7, 6],
            'BOX-MIX-001' => [45, 40, 35, 32, 28],
            'SHL-CRB-001' => [8, 6.5, 5, 4.5, 4],
            'SPC-SHK-001' => [2.5, 2.0, 1.5, 1.2, 1.0],
        ];

        foreach ($prices as $sku => [$retail, $rest, $whBase, $wh50, $wh100]) {
            $productId = $productModels[$sku]->id;

            PriceListItem::firstOrCreate(
                ['pricing_tier_id' => $retailTier->id, 'product_id' => $productId, 'min_quantity' => 0],
                ['price_per_unit' => $retail]
            );
            PriceListItem::firstOrCreate(
                ['pricing_tier_id' => $restaurantTier->id, 'product_id' => $productId, 'min_quantity' => 0],
                ['price_per_unit' => $rest]
            );
            PriceListItem::firstOrCreate(
                ['pricing_tier_id' => $wholesaleTier->id, 'product_id' => $productId, 'min_quantity' => 0],
                ['price_per_unit' => $whBase]
            );
            PriceListItem::firstOrCreate(
                ['pricing_tier_id' => $wholesaleTier->id, 'product_id' => $productId, 'min_quantity' => 50],
                ['price_per_unit' => $wh50]
            );
            PriceListItem::firstOrCreate(
                ['pricing_tier_id' => $wholesaleTier->id, 'product_id' => $productId, 'min_quantity' => 100],
                ['price_per_unit' => $wh100]
            );
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
                'credit_limit' => 5000,
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
                'credit_limit' => 3000,
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
                'credit_limit' => 25000,
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
                'credit_limit' => 15000,
                'payment_terms_days' => 21,
            ]
        );

        // Negotiated restaurant override on lobster
        CustomerPriceOverride::firstOrCreate(
            [
                'customer_id' => $oceanGrill->id,
                'product_id' => $productModels['SHL-LOB-001']->id,
            ],
            ['price_per_unit' => 27.50]
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
                ['sku' => 'FISH-TUN-001', 'qty' => 120, 'cost' => 6],
                ['sku' => 'FISH-KIN-001', 'qty' => 80, 'cost' => 5],
                ['sku' => 'SHL-LOB-001', 'qty' => 40, 'cost' => 18],
                ['sku' => 'SHL-PRW-001', 'qty' => 60, 'cost' => 9],
                ['sku' => 'CEP-SQD-001', 'qty' => 50, 'cost' => 4],
                ['sku' => 'BOX-MIX-001', 'qty' => 25, 'cost' => 20],
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
                    'product_id' => $productModels['FISH-SAL-001']->id,
                    'supplier_id' => $importer->id,
                    'batch_code' => 'BCH-DEMO-SALMON01',
                    'catch_date' => now()->subDays(10)->toDateString(),
                    'production_date' => now()->subDays(8)->toDateString(),
                    'expiry_date' => now()->addDays(1)->toDateString(),
                    'qty' => 30,
                    'storage_location' => StorageLocation::Frozen,
                    'unit_cost' => 12,
                ],
                [
                    'product_id' => $productModels['FISH-SNP-001']->id,
                    'supplier_id' => $fisherman->id,
                    'batch_code' => 'BCH-DEMO-SNAP01',
                    'catch_date' => now()->subDay()->toDateString(),
                    'production_date' => now()->toDateString(),
                    'expiry_date' => now()->addDays(3)->toDateString(),
                    'qty' => 45,
                    'storage_location' => StorageLocation::Fresh,
                    'unit_cost' => 8,
                ],
                [
                    'product_id' => $productModels['SHL-CRB-001']->id,
                    'supplier_id' => $fisherman->id,
                    'batch_code' => 'BCH-DEMO-CRAB01',
                    'catch_date' => now()->subDays(2)->toDateString(),
                    'production_date' => now()->subDay()->toDateString(),
                    'expiry_date' => now()->addDays(7)->toDateString(),
                    'qty' => 100,
                    'storage_location' => StorageLocation::Chilled,
                    'unit_cost' => 2.5,
                ],
                [
                    'product_id' => $productModels['SPC-SHK-001']->id,
                    'supplier_id' => $importer->id,
                    'batch_code' => 'BCH-DEMO-SPEC01',
                    'catch_date' => now()->subDays(20)->toDateString(),
                    'production_date' => now()->subDays(15)->toDateString(),
                    'expiry_date' => now()->addDays(90)->toDateString(),
                    'qty' => 500,
                    'storage_location' => StorageLocation::Frozen,
                    'unit_cost' => 0.8,
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
        }

        // Record a bit of wastage for dashboard stats
        $nearExpiryBatch = Batch::where('batch_code', 'BCH-DEMO-SALMON01')->first();
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
                'product_id' => $productModels['FISH-TUN-001']->id,
                'quantity' => 3,
                'unit_price' => 18,
                'subtotal' => 54,
            ]);
            SalesOrderLine::create([
                'sales_order_id' => $retailOrder->id,
                'product_id' => $productModels['SHL-CRB-001']->id,
                'quantity' => 4,
                'unit_price' => 8,
                'subtotal' => 32,
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
                'product_id' => $productModels['SHL-LOB-001']->id,
                'quantity' => 8,
                'unit_price' => 27.50, // override
                'subtotal' => 220,
            ]);
            SalesOrderLine::create([
                'sales_order_id' => $restOrder->id,
                'product_id' => $productModels['FISH-SNP-001']->id,
                'quantity' => 12,
                'unit_price' => 17,
                'subtotal' => 204,
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
                    200,
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
                'product_id' => $productModels['FISH-TUN-001']->id,
                'quantity' => 55, // hits qty break @50
                'unit_price' => 10,
                'subtotal' => 550,
            ]);
            SalesOrderLine::create([
                'sales_order_id' => $whOrder->id,
                'product_id' => $productModels['SHL-PRW-001']->id,
                'quantity' => 40,
                'unit_price' => 15,
                'subtotal' => 600,
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
