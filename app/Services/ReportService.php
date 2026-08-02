<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Models\Batch;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates reporting data for dashboard widgets and CSV/PDF exports.
 */
class ReportService
{
    /**
     * @return Collection<int, object{channel: string, order_count: int, revenue: float}>
     */
    public function salesByChannel(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        [$from, $to] = $this->range($from, $to);

        return collect(DB::table('sales_order_lines')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->whereNotIn('sales_orders.status', ['draft', 'cancelled'])
            ->whereDate('sales_orders.order_date', '>=', $from->toDateString())
            ->whereDate('sales_orders.order_date', '<=', $to->toDateString())
            ->groupBy('sales_orders.channel')
            ->select([
                'sales_orders.channel',
                DB::raw('COUNT(DISTINCT sales_orders.id) as order_count'),
                DB::raw('COALESCE(SUM(sales_order_lines.subtotal), 0) as revenue'),
            ])
            ->get());
    }

    /**
     * @return Collection<int, object>
     */
    public function topProducts(int $limit = 10, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        [$from, $to] = $this->range($from, $to);

        return collect(DB::table('sales_order_lines')
            ->join('products', 'products.id', '=', 'sales_order_lines.product_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->whereNotIn('sales_orders.status', ['draft', 'cancelled'])
            ->whereDate('sales_orders.order_date', '>=', $from->toDateString())
            ->whereDate('sales_orders.order_date', '<=', $to->toDateString())
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->select([
                'products.id',
                'products.name',
                'products.sku',
                DB::raw('SUM(sales_order_lines.quantity) as total_qty'),
                DB::raw('SUM(sales_order_lines.subtotal) as revenue'),
            ])
            ->orderByDesc('total_qty')
            ->limit($limit)
            ->get());
    }

    /**
     * @return Collection<int, object{bucket: string, batch_count: int, quantity: float}>
     */
    public function stockAging(): Collection
    {
        $today = now()->startOfDay();

        $batches = Batch::query()
            ->where('quantity_available', '>', 0)
            ->get(['id', 'expiry_date', 'quantity_available']);

        $buckets = [
            'Expired'   => ['count' => 0, 'qty' => 0.0],
            '0–3 days'  => ['count' => 0, 'qty' => 0.0],
            '4–7 days'  => ['count' => 0, 'qty' => 0.0],
            '8–30 days' => ['count' => 0, 'qty' => 0.0],
            '31+ days'  => ['count' => 0, 'qty' => 0.0],
        ];

        foreach ($batches as $batch) {
            $days = (int) $today->diffInDays($batch->expiry_date, false);

            $key = match (true) {
                $days < 0   => 'Expired',
                $days <= 3  => '0–3 days',
                $days <= 7  => '4–7 days',
                $days <= 30 => '8–30 days',
                default     => '31+ days',
            };

            $buckets[$key]['count']++;
            $buckets[$key]['qty'] += (float) $batch->quantity_available;
        }

        return collect($buckets)->map(fn ($v, $k) => (object) [
            'bucket'      => $k,
            'batch_count' => $v['count'],
            'quantity'    => round($v['qty'], 3),
        ])->values();
    }

    /**
     * @return object{wastage_qty: float, sales_qty: float, total_out: float, wastage_pct: float}
     */
    public function wastagePercent(?Carbon $from = null, ?Carbon $to = null): object
    {
        [$from, $to] = $this->range($from, $to);

        $wastage = (float) StockMovement::query()
            ->where('type', StockMovementType::WastageOut)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->sum('quantity');

        $sales = (float) StockMovement::query()
            ->where('type', StockMovementType::SaleOut)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->sum('quantity');

        $totalOut = $wastage + $sales;

        return (object) [
            'wastage_qty' => round($wastage, 3),
            'sales_qty'   => round($sales, 3),
            'total_out'   => round($totalOut, 3),
            'wastage_pct' => $totalOut > 0 ? round(($wastage / $totalOut) * 100, 2) : 0.0,
        ];
    }

    /**
     * @return Collection<int, object{customer_type: string, order_count: int, revenue: float}>
     */
    public function revenueByCustomerType(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        [$from, $to] = $this->range($from, $to);

        return collect(DB::table('sales_order_lines')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->join('customers', 'customers.id', '=', 'sales_orders.customer_id')
            ->whereNotIn('sales_orders.status', ['draft', 'cancelled'])
            ->whereDate('sales_orders.order_date', '>=', $from->toDateString())
            ->whereDate('sales_orders.order_date', '<=', $to->toDateString())
            ->groupBy('customers.type')
            ->select([
                'customers.type as customer_type',
                DB::raw('COUNT(DISTINCT sales_orders.id) as order_count'),
                DB::raw('COALESCE(SUM(sales_order_lines.subtotal), 0) as revenue'),
            ])
            ->get());
    }

    /**
     * Full dashboard report payload matching the sales/ops scorecard layout.
     *
     * @return array{
     *     total_sales: float,
     *     outstanding_debt: float,
     *     unpaid_invoices: int,
     *     customers_owing: int,
     *     total_collection: float,
     *     collection_rate: float,
     *     paid_invoices: int,
     *     total_kg_sold: float,
     *     salesperson_outstanding: Collection,
     *     salesperson_kg: Collection,
     *     product_kg: Collection,
     *     customer_type_kg: Collection,
     *     customer_type_sales: Collection,
     *     form_kg: Collection
     * }
     */
    public function dashboardReport(?Carbon $from = null, ?Carbon $to = null): array
    {
        [$from, $to] = $this->range($from, $to);
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $invoiceBase = DB::table('invoices')
            ->join('sales_orders', 'sales_orders.id', '=', 'invoices.sales_order_id')
            ->whereNotIn('sales_orders.status', ['draft', 'cancelled'])
            ->where('invoices.status', '!=', 'cancelled')
            ->whereDate('sales_orders.order_date', '>=', $fromDate)
            ->whereDate('sales_orders.order_date', '<=', $toDate);

        $totals = (clone $invoiceBase)
            ->selectRaw('COALESCE(SUM(invoices.total_amount), 0) as total_sales')
            ->selectRaw('COALESCE(SUM(invoices.amount_paid), 0) as total_collection')
            ->selectRaw('COALESCE(SUM(CASE WHEN invoices.total_amount > invoices.amount_paid THEN invoices.total_amount - invoices.amount_paid ELSE 0 END), 0) as outstanding_debt')
            ->first();

        $totalSales = round((float) ($totals->total_sales ?? 0), 2);
        $totalCollection = round((float) ($totals->total_collection ?? 0), 2);
        $outstandingDebt = round((float) ($totals->outstanding_debt ?? 0), 2);

        $unpaidInvoices = (int) (clone $invoiceBase)
            ->whereColumn('invoices.amount_paid', '<', 'invoices.total_amount')
            ->selectRaw('COUNT(DISTINCT invoices.id) as aggregate')
            ->value('aggregate');

        $paidInvoices = (int) (clone $invoiceBase)
            ->whereColumn('invoices.amount_paid', '>=', 'invoices.total_amount')
            ->where('invoices.total_amount', '>', 0)
            ->selectRaw('COUNT(DISTINCT invoices.id) as aggregate')
            ->value('aggregate');

        $customersOwing = (int) (clone $invoiceBase)
            ->whereColumn('invoices.amount_paid', '<', 'invoices.total_amount')
            ->selectRaw('COUNT(DISTINCT sales_orders.customer_id) as aggregate')
            ->value('aggregate');

        $lineBase = DB::table('sales_order_lines')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->whereNotIn('sales_orders.status', ['draft', 'cancelled'])
            ->whereDate('sales_orders.order_date', '>=', $fromDate)
            ->whereDate('sales_orders.order_date', '<=', $toDate);

        $totalKgSold = round((float) (clone $lineBase)->sum('sales_order_lines.quantity'), 3);

        $salespersonOutstanding = collect(DB::table('invoices')
            ->join('sales_orders', 'sales_orders.id', '=', 'invoices.sales_order_id')
            ->leftJoin('users', 'users.id', '=', 'sales_orders.created_by')
            ->whereNotIn('sales_orders.status', ['draft', 'cancelled'])
            ->where('invoices.status', '!=', 'cancelled')
            ->whereColumn('invoices.amount_paid', '<', 'invoices.total_amount')
            ->whereDate('sales_orders.order_date', '>=', $fromDate)
            ->whereDate('sales_orders.order_date', '<=', $toDate)
            ->groupBy('sales_orders.created_by', 'users.name')
            ->select([
                DB::raw("COALESCE(users.name, 'Unassigned') as salesperson"),
                DB::raw('COALESCE(SUM(invoices.total_amount - invoices.amount_paid), 0) as outstanding'),
            ])
            ->orderByDesc('outstanding')
            ->get());

        $salespersonKg = collect((clone $lineBase)
            ->leftJoin('users', 'users.id', '=', 'sales_orders.created_by')
            ->groupBy('sales_orders.created_by', 'users.name')
            ->select([
                DB::raw("COALESCE(users.name, 'Unassigned') as salesperson"),
                DB::raw('COALESCE(SUM(sales_order_lines.quantity), 0) as kg_sold'),
            ])
            ->orderByDesc('kg_sold')
            ->get());

        $productKg = collect((clone $lineBase)
            ->join('products', 'products.id', '=', 'sales_order_lines.product_id')
            ->groupBy('products.id', 'products.name')
            ->select([
                'products.name as product',
                DB::raw('COALESCE(SUM(sales_order_lines.quantity), 0) as kg_sold'),
            ])
            ->orderByDesc('kg_sold')
            ->limit(12)
            ->get());

        $customerTypeKg = collect((clone $lineBase)
            ->join('customers', 'customers.id', '=', 'sales_orders.customer_id')
            ->groupBy('customers.type')
            ->select([
                'customers.type as customer_type',
                DB::raw('COALESCE(SUM(sales_order_lines.quantity), 0) as kg_sold'),
            ])
            ->orderByDesc('kg_sold')
            ->get());

        $customerTypeSales = collect(DB::table('sales_order_lines')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->join('customers', 'customers.id', '=', 'sales_orders.customer_id')
            ->whereNotIn('sales_orders.status', ['draft', 'cancelled'])
            ->whereDate('sales_orders.order_date', '>=', $fromDate)
            ->whereDate('sales_orders.order_date', '<=', $toDate)
            ->groupBy('customers.type')
            ->select([
                'customers.type as customer_type',
                DB::raw('COALESCE(SUM(sales_order_lines.subtotal), 0) as total_sales'),
            ])
            ->orderByDesc('total_sales')
            ->get());

        $formKg = collect((clone $lineBase)
            ->leftJoin('product_forms', 'product_forms.id', '=', 'sales_order_lines.product_form_id')
            ->groupBy(DB::raw("COALESCE(product_forms.code, LOWER(COALESCE(product_forms.name, 'whole')))"))
            ->select([
                DB::raw("COALESCE(MAX(product_forms.name), 'Whole') as form"),
                DB::raw('COALESCE(SUM(sales_order_lines.quantity), 0) as kg_sold'),
            ])
            ->orderByDesc('kg_sold')
            ->get());

        return [
            'total_sales' => $totalSales,
            'outstanding_debt' => $outstandingDebt,
            'unpaid_invoices' => $unpaidInvoices,
            'customers_owing' => $customersOwing,
            'total_collection' => $totalCollection,
            'collection_rate' => $totalSales > 0
                ? round(($totalCollection / $totalSales) * 100, 2)
                : 0.0,
            'paid_invoices' => $paidInvoices,
            'total_kg_sold' => $totalKgSold,
            'salesperson_outstanding' => $salespersonOutstanding,
            'salesperson_kg' => $salespersonKg,
            'product_kg' => $productKg,
            'customer_type_kg' => $customerTypeKg,
            'customer_type_sales' => $customerTypeSales,
            'form_kg' => $formKg,
            'from' => $fromDate,
            'to' => $toDate,
        ];
    }

    /**
     * @return Collection<int, object>
     */
    public function outstandingDebtByCustomer(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        [$from, $to] = $this->range($from, $to);

        return collect(DB::table('invoices')
            ->join('sales_orders', 'sales_orders.id', '=', 'invoices.sales_order_id')
            ->join('customers', 'customers.id', '=', 'sales_orders.customer_id')
            ->whereNotIn('sales_orders.status', ['draft', 'cancelled'])
            ->where('invoices.status', '!=', 'cancelled')
            ->whereColumn('invoices.amount_paid', '<', 'invoices.total_amount')
            ->whereDate('sales_orders.order_date', '>=', $from->toDateString())
            ->whereDate('sales_orders.order_date', '<=', $to->toDateString())
            ->groupBy('customers.id', 'customers.name', 'customers.type')
            ->select([
                'customers.name as customer',
                'customers.type as customer_type',
                DB::raw('COUNT(DISTINCT invoices.id) as unpaid_invoices'),
                DB::raw('COALESCE(SUM(invoices.total_amount - invoices.amount_paid), 0) as outstanding'),
            ])
            ->orderByDesc('outstanding')
            ->get());
    }

    /**
     * @return Collection<int, object>
     */
    public function paymentsReceived(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        [$from, $to] = $this->range($from, $to);

        return collect(DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'invoices.sales_order_id')
            ->join('customers', 'customers.id', '=', 'sales_orders.customer_id')
            ->whereBetween('payments.paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderByDesc('payments.paid_at')
            ->select([
                'payments.paid_at',
                'invoices.invoice_number',
                'customers.name as customer',
                'payments.payment_method',
                'payments.amount',
            ])
            ->limit(200)
            ->get());
    }

    /**
     * @return Collection<int, object>
     */
    public function debtBySalesperson(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        [$from, $to] = $this->range($from, $to);

        return collect(DB::table('invoices')
            ->join('sales_orders', 'sales_orders.id', '=', 'invoices.sales_order_id')
            ->leftJoin('users', 'users.id', '=', 'sales_orders.created_by')
            ->whereNotIn('sales_orders.status', ['draft', 'cancelled'])
            ->where('invoices.status', '!=', 'cancelled')
            ->whereColumn('invoices.amount_paid', '<', 'invoices.total_amount')
            ->whereDate('sales_orders.order_date', '>=', $from->toDateString())
            ->whereDate('sales_orders.order_date', '<=', $to->toDateString())
            ->groupBy('sales_orders.created_by', 'users.name')
            ->select([
                DB::raw("COALESCE(users.name, 'Unassigned') as salesperson"),
                DB::raw('COUNT(DISTINCT invoices.id) as unpaid_invoices'),
                DB::raw('COALESCE(SUM(invoices.total_amount - invoices.amount_paid), 0) as outstanding'),
            ])
            ->orderByDesc('outstanding')
            ->get());
    }

    /**
     * @return Collection<int, object>
     */
    public function salesByProductForm(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        [$from, $to] = $this->range($from, $to);

        return collect(DB::table('sales_order_lines')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->leftJoin('product_forms', 'product_forms.id', '=', 'sales_order_lines.product_form_id')
            ->whereNotIn('sales_orders.status', ['draft', 'cancelled'])
            ->whereDate('sales_orders.order_date', '>=', $from->toDateString())
            ->whereDate('sales_orders.order_date', '<=', $to->toDateString())
            ->groupBy(DB::raw("COALESCE(product_forms.code, LOWER(COALESCE(product_forms.name, 'whole')))"))
            ->select([
                DB::raw("COALESCE(MAX(product_forms.name), 'Whole') as form"),
                DB::raw('COALESCE(SUM(sales_order_lines.quantity), 0) as qty'),
                DB::raw('COALESCE(SUM(sales_order_lines.subtotal), 0) as revenue'),
            ])
            ->orderByDesc('qty')
            ->get());
    }

    /**
     * @return Collection<int, object>
     */
    public function revenueBySalesperson(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        [$from, $to] = $this->range($from, $to);

        return collect(DB::table('sales_order_lines')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->leftJoin('users', 'users.id', '=', 'sales_orders.created_by')
            ->whereNotIn('sales_orders.status', ['draft', 'cancelled'])
            ->whereDate('sales_orders.order_date', '>=', $from->toDateString())
            ->whereDate('sales_orders.order_date', '<=', $to->toDateString())
            ->groupBy('sales_orders.created_by', 'users.name')
            ->select([
                DB::raw("COALESCE(users.name, 'Unassigned') as salesperson"),
                DB::raw('COUNT(DISTINCT sales_orders.id) as order_count'),
                DB::raw('COALESCE(SUM(sales_order_lines.quantity), 0) as qty'),
                DB::raw('COALESCE(SUM(sales_order_lines.subtotal), 0) as revenue'),
            ])
            ->orderByDesc('revenue')
            ->get());
    }

    /**
     * @return Collection<int, object>
     */
    public function wastageDetail(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        [$from, $to] = $this->range($from, $to);

        return collect(DB::table('stock_movements')
            ->join('batches', 'batches.id', '=', 'stock_movements.batch_id')
            ->join('products', 'products.id', '=', 'batches.product_id')
            ->leftJoin('product_forms', 'product_forms.id', '=', 'batches.product_form_id')
            ->where('stock_movements.type', StockMovementType::WastageOut->value)
            ->whereBetween('stock_movements.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderByDesc('stock_movements.created_at')
            ->select([
                'stock_movements.created_at',
                'products.name as product',
                DB::raw("COALESCE(product_forms.name, 'Whole') as form"),
                'batches.batch_code',
                'stock_movements.quantity',
                'stock_movements.reason',
            ])
            ->limit(200)
            ->get());
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(?Carbon $from, ?Carbon $to): array
    {
        return [
            ($from ?? now()->subDays(30))->copy()->startOfDay(),
            ($to ?? now())->copy()->endOfDay(),
        ];
    }
}
