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
