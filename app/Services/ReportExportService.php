<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports report datasets to CSV or PDF.
 */
class ReportExportService
{
    public function __construct(private readonly ReportService $reports) {}

    public function export(string $report, string $format, ?Carbon $from = null, ?Carbon $to = null): StreamedResponse|Response
    {
        $from ??= now()->subDays(30);
        $to ??= now();

        $payload = match ($report) {
            'sales_by_channel' => [
                'title'   => 'Sales by Channel',
                'headers' => ['Channel', 'Orders', 'Revenue'],
                'rows'    => $this->reports->salesByChannel($from, $to)->map(fn ($r) => [
                    $r->channel, $r->order_count, number_format((float) $r->revenue, 2, '.', ''),
                ])->all(),
            ],
            'top_products' => [
                'title'   => 'Top Products',
                'headers' => ['Product', 'SKU', 'Qty Sold', 'Revenue'],
                'rows'    => $this->reports->topProducts(50, $from, $to)->map(fn ($r) => [
                    $r->name, $r->sku, $r->total_qty, number_format((float) $r->revenue, 2, '.', ''),
                ])->all(),
            ],
            'stock_aging' => [
                'title'   => 'Stock Aging',
                'headers' => ['Bucket', 'Batches', 'Quantity'],
                'rows'    => $this->reports->stockAging()->map(fn ($r) => [
                    $r->bucket, $r->batch_count, $r->quantity,
                ])->all(),
            ],
            'wastage' => [
                'title'   => 'Wastage Summary',
                'headers' => ['Wastage Qty', 'Sales Qty', 'Total Out', 'Wastage %'],
                'rows'    => (function () use ($from, $to) {
                    $w = $this->reports->wastagePercent($from, $to);

                    return [[$w->wastage_qty, $w->sales_qty, $w->total_out, $w->wastage_pct]];
                })(),
            ],
            'revenue_by_customer_type' => [
                'title'   => 'Revenue by Customer Type',
                'headers' => ['Customer Type', 'Orders', 'Revenue'],
                'rows'    => $this->reports->revenueByCustomerType($from, $to)->map(fn ($r) => [
                    $r->customer_type, $r->order_count, number_format((float) $r->revenue, 2, '.', ''),
                ])->all(),
            ],
            default => throw new \InvalidArgumentException("Unknown report: {$report}"),
        };

        $filename = $report . '_' . $from->format('Ymd') . '_' . $to->format('Ymd');

        return $format === 'pdf'
            ? $this->toPdf($payload, $filename, $from, $to)
            : $this->toCsv($payload, $filename);
    }

    private function toCsv(array $payload, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($payload) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $payload['headers']);
            foreach ($payload['rows'] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    private function toPdf(array $payload, string $filename, Carbon $from, Carbon $to): Response
    {
        $pdf = Pdf::loadView('reports.export', [
            'title'   => $payload['title'],
            'headers' => $payload['headers'],
            'rows'    => $payload['rows'],
            'from'    => $from,
            'to'      => $to,
        ]);

        return $pdf->download($filename . '.pdf');
    }
}
