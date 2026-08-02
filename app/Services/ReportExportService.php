<?php

namespace App\Services;

use App\Enums\CustomerType;
use App\Enums\PaymentMethod;
use App\Enums\SalesChannel;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports report datasets to CSV or PDF.
 */
class ReportExportService
{
    public const CATEGORY_SALES = 'sales';

    public const CATEGORY_PAYMENTS = 'payments';

    public const CATEGORY_WASTAGE = 'wastage';

    public function __construct(private readonly ReportService $reports) {}

    /**
     * @return array<string, string>
     */
    public function reportsForCategory(string $category): array
    {
        return match ($category) {
            self::CATEGORY_SALES => [
                'sales_by_channel' => 'Sales by Channel',
                'top_products' => 'Top Products',
                'revenue_by_customer_type' => 'Revenue by Customer Type',
                'sales_by_product_form' => 'Sales by Product Form',
                'revenue_by_salesperson' => 'Revenue by Salesperson',
            ],
            self::CATEGORY_PAYMENTS => [
                'outstanding_debt' => 'Outstanding Debt by Customer',
                'payments_received' => 'Payments Received',
                'debt_by_salesperson' => 'Debt by Salesperson',
            ],
            self::CATEGORY_WASTAGE => [
                'wastage' => 'Wastage Summary',
                'wastage_detail' => 'Wastage Detail',
                'stock_aging' => 'Stock Aging',
            ],
            default => [],
        };
    }

    public function defaultReportForCategory(string $category): string
    {
        return array_key_first($this->reportsForCategory($category)) ?? 'sales_by_channel';
    }

    /**
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    public function build(string $report, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $from ??= now()->subDays(30);
        $to ??= now();

        return match ($report) {
            'sales_by_channel' => [
                'title'   => 'Sales by Channel',
                'headers' => ['Channel', 'Orders', 'Revenue'],
                'rows'    => $this->reports->salesByChannel($from, $to)->map(fn ($r) => [
                    SalesChannel::tryFrom($r->channel)?->label() ?? $r->channel,
                    $r->order_count,
                    number_format((float) $r->revenue, 0, '.', ''),
                ])->all(),
            ],
            'top_products' => [
                'title'   => 'Top Products',
                'headers' => ['Product', 'SKU', 'Qty Sold', 'Revenue'],
                'rows'    => $this->reports->topProducts(50, $from, $to)->map(fn ($r) => [
                    $r->name, $r->sku, $r->total_qty, number_format((float) $r->revenue, 0, '.', ''),
                ])->all(),
            ],
            'revenue_by_customer_type' => [
                'title'   => 'Revenue by Customer Type',
                'headers' => ['Customer Type', 'Orders', 'Revenue'],
                'rows'    => $this->reports->revenueByCustomerType($from, $to)->map(fn ($r) => [
                    CustomerType::tryFrom($r->customer_type)?->label() ?? $r->customer_type,
                    $r->order_count,
                    number_format((float) $r->revenue, 0, '.', ''),
                ])->all(),
            ],
            'sales_by_product_form' => [
                'title'   => 'Sales by Product Form',
                'headers' => ['Form', 'Qty Sold', 'Revenue'],
                'rows'    => $this->reports->salesByProductForm($from, $to)->map(fn ($r) => [
                    $r->form,
                    $r->qty,
                    number_format((float) $r->revenue, 0, '.', ''),
                ])->all(),
            ],
            'revenue_by_salesperson' => [
                'title'   => 'Revenue by Salesperson',
                'headers' => ['Salesperson', 'Orders', 'Qty Sold', 'Revenue'],
                'rows'    => $this->reports->revenueBySalesperson($from, $to)->map(fn ($r) => [
                    $r->salesperson,
                    $r->order_count,
                    $r->qty,
                    number_format((float) $r->revenue, 0, '.', ''),
                ])->all(),
            ],
            'outstanding_debt' => [
                'title'   => 'Outstanding Debt by Customer',
                'headers' => ['Customer', 'Type', 'Unpaid Invoices', 'Outstanding'],
                'rows'    => $this->reports->outstandingDebtByCustomer($from, $to)->map(fn ($r) => [
                    $r->customer,
                    CustomerType::tryFrom($r->customer_type)?->label() ?? $r->customer_type,
                    $r->unpaid_invoices,
                    number_format((float) $r->outstanding, 0, '.', ''),
                ])->all(),
            ],
            'payments_received' => [
                'title'   => 'Payments Received',
                'headers' => ['Paid At', 'Invoice', 'Customer', 'Method', 'Amount'],
                'rows'    => $this->reports->paymentsReceived($from, $to)->map(fn ($r) => [
                    Carbon::parse($r->paid_at)->toDateTimeString(),
                    $r->invoice_number,
                    $r->customer,
                    PaymentMethod::tryFrom($r->payment_method)?->label() ?? $r->payment_method,
                    number_format((float) $r->amount, 0, '.', ''),
                ])->all(),
            ],
            'debt_by_salesperson' => [
                'title'   => 'Debt by Salesperson',
                'headers' => ['Salesperson', 'Unpaid Invoices', 'Outstanding'],
                'rows'    => $this->reports->debtBySalesperson($from, $to)->map(fn ($r) => [
                    $r->salesperson,
                    $r->unpaid_invoices,
                    number_format((float) $r->outstanding, 0, '.', ''),
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
            'wastage_detail' => [
                'title'   => 'Wastage Detail',
                'headers' => ['When', 'Product', 'Form', 'Batch', 'Qty', 'Reason'],
                'rows'    => $this->reports->wastageDetail($from, $to)->map(fn ($r) => [
                    Carbon::parse($r->created_at)->toDateTimeString(),
                    $r->product,
                    $r->form,
                    $r->batch_code,
                    $r->quantity,
                    $r->reason,
                ])->all(),
            ],
            'stock_aging' => [
                'title'   => 'Stock Aging',
                'headers' => ['Bucket', 'Batches', 'Quantity'],
                'rows'    => $this->reports->stockAging()->map(fn ($r) => [
                    $r->bucket, $r->batch_count, $r->quantity,
                ])->all(),
            ],
            default => throw new \InvalidArgumentException("Unknown report: {$report}"),
        };
    }

    public function export(string $report, string $format, ?Carbon $from = null, ?Carbon $to = null): StreamedResponse|Response
    {
        $from ??= now()->subDays(30);
        $to ??= now();

        $payload = $this->build($report, $from, $to);
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
