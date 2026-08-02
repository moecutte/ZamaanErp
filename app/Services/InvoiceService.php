<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\SalesChannel;
use App\Enums\SalesOrderStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Creates invoices from confirmed sales orders and keeps invoice status
 * in sync with recorded payments.
 */
class InvoiceService
{
    /**
     * Generate an invoice for a sales order (idempotent — returns existing if present).
     */
    public function generateForOrder(SalesOrder $order, ?PaymentMethod $retailMethod = PaymentMethod::Cash): Invoice
    {
        return DB::transaction(function () use ($order, $retailMethod) {
            $existing = Invoice::query()
                ->where('sales_order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing->load('payments');
            }

            $order->load(['lines', 'customer']);

            $total = round((float) $order->lines->sum('subtotal'), 2);
            $issueDate = now()->toDateString();
            $termsDays = (int) ($order->customer->payment_terms_days ?? 0);
            $dueDate = now()->addDays($termsDays)->toDateString();

            $isPos = $order->channel === SalesChannel::Pos;

            $invoice = Invoice::create([
                'sales_order_id' => $order->id,
                'invoice_number' => $this->nextInvoiceNumber(),
                'issue_date'     => $issueDate,
                'due_date'       => $isPos ? $issueDate : $dueDate,
                'total_amount'   => $total,
                'amount_paid'    => 0,
                'status'         => InvoiceStatus::Unpaid,
            ]);

            if ($isPos && $total > 0) {
                $this->applyPayment(
                    invoice: $invoice,
                    amount: $total,
                    method: $retailMethod ?? PaymentMethod::Cash,
                    paidAt: now(),
                    recordedBy: Auth::id() ?? $order->created_by,
                );
            }

            $order->update(['status' => SalesOrderStatus::Invoiced]);

            return $invoice->fresh(['payments']);
        });
    }

    public function applyPayment(
        Invoice $invoice,
        float $amount,
        PaymentMethod $method,
        mixed $paidAt = null,
        ?int $recordedBy = null,
    ): Payment {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }

        return DB::transaction(function () use ($invoice, $amount, $method, $paidAt, $recordedBy) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status === InvoiceStatus::Cancelled) {
                throw new \RuntimeException('Cannot record payment on a cancelled invoice.');
            }

            $outstanding = round((float) $invoice->total_amount - (float) $invoice->amount_paid, 2);

            if ($amount > $outstanding + 0.001) {
                throw new \RuntimeException(
                    "Payment of {$amount} exceeds outstanding balance of {$outstanding}."
                );
            }

            $payment = Payment::create([
                'invoice_id'     => $invoice->id,
                'amount'         => $amount,
                'payment_method' => $method,
                'paid_at'        => $paidAt ?? now(),
                'recorded_by'    => $recordedBy ?? Auth::id(),
            ]);

            $invoice->amount_paid = round((float) $invoice->amount_paid + $amount, 2);
            $invoice->status = $this->resolveStatus($invoice);
            $invoice->save();

            return $payment;
        });
    }

    public function refreshStatus(Invoice $invoice): InvoiceStatus
    {
        if ($invoice->status === InvoiceStatus::Cancelled) {
            return InvoiceStatus::Cancelled;
        }

        $status = $this->resolveStatus($invoice);
        $invoice->update(['status' => $status]);

        return $status;
    }

    /**
     * Mark all past-due unpaid/partial invoices as overdue.
     */
    public function markOverdueInvoices(): int
    {
        $count = 0;

        Invoice::query()
            ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Partial, InvoiceStatus::Overdue])
            ->whereDate('due_date', '<', now()->toDateString())
            ->whereColumn('amount_paid', '<', 'total_amount')
            ->each(function (Invoice $invoice) use (&$count) {
                if ($invoice->status !== InvoiceStatus::Overdue) {
                    $invoice->update(['status' => InvoiceStatus::Overdue]);
                    $count++;
                }
            });

        return $count;
    }

    public function resolveStatus(Invoice $invoice): InvoiceStatus
    {
        if ($invoice->status === InvoiceStatus::Cancelled) {
            return InvoiceStatus::Cancelled;
        }

        $paid = (float) $invoice->amount_paid;
        $total = (float) $invoice->total_amount;

        if ($paid >= $total && $total > 0) {
            return InvoiceStatus::Paid;
        }

        $isPastDue = $invoice->due_date
            && $invoice->due_date->toDateString() < now()->toDateString();

        if ($paid > 0) {
            return $isPastDue ? InvoiceStatus::Overdue : InvoiceStatus::Partial;
        }

        return $isPastDue ? InvoiceStatus::Overdue : InvoiceStatus::Unpaid;
    }

    /**
     * Outstanding unpaid balance across open invoices for a customer.
     */
    public function outstandingBalance(Customer $customer): float
    {
        return (float) Invoice::query()
            ->whereHas('salesOrder', fn ($q) => $q
                ->where('customer_id', $customer->id)
                ->whereNot('status', SalesOrderStatus::Cancelled))
            ->whereNotIn('status', [InvoiceStatus::Paid, InvoiceStatus::Cancelled])
            ->selectRaw('COALESCE(SUM(total_amount - amount_paid), 0) as outstanding')
            ->value('outstanding');
    }

    private function nextInvoiceNumber(): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $lockName = 'invoice_number_' . now()->format('Ymd');
            $gotLock = DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);

            if (! $gotLock || (int) $gotLock->acquired !== 1) {
                throw new \RuntimeException('Could not acquire invoice number lock.');
            }

            try {
                return $this->allocateInvoiceNumber();
            } finally {
                DB::select('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
        }

        // SQLite / other drivers: rely on row lock within the open transaction
        return $this->allocateInvoiceNumber(lockRows: true);
    }

    private function allocateInvoiceNumber(bool $lockRows = false): string
    {
        $prefix = 'INV-' . now()->format('Ymd') . '-';
        $query = Invoice::where('invoice_number', 'like', $prefix . '%')
            ->orderByDesc('invoice_number');

        if ($lockRows) {
            $query->lockForUpdate();
        }

        $last = $query->value('invoice_number');
        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
