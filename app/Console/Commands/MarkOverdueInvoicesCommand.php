<?php

namespace App\Console\Commands;

use App\Services\InvoiceService;
use Illuminate\Console\Command;

class MarkOverdueInvoicesCommand extends Command
{
    protected $signature = 'invoices:mark-overdue';

    protected $description = 'Mark past-due unpaid/partial invoices as overdue';

    public function handle(InvoiceService $invoices): int
    {
        $count = $invoices->markOverdueInvoices();

        $this->info("Marked {$count} invoice(s) as overdue.");

        return self::SUCCESS;
    }
}
