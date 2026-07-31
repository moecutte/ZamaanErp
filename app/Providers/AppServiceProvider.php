<?php

namespace App\Providers;

use App\Models\Batch;
use App\Observers\BatchObserver;
use App\Services\CancelSalesOrderService;
use App\Services\ConfirmSalesOrderService;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\PricingResolutionService;
use App\Services\ReceivePurchaseOrderService;
use App\Services\ReportExportService;
use App\Services\ReportService;
use App\Services\StockAllocationService;
use App\Services\StockService;
use App\Services\WastageService;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StockAllocationService::class);
        $this->app->singleton(StockService::class);
        $this->app->singleton(ReceivePurchaseOrderService::class);
        $this->app->singleton(PricingResolutionService::class);
        $this->app->singleton(ConfirmSalesOrderService::class);
        $this->app->singleton(CancelSalesOrderService::class);
        $this->app->singleton(WastageService::class);
        $this->app->singleton(InvoiceService::class);
        $this->app->singleton(DeliveryService::class);
        $this->app->singleton(ReportService::class);
        $this->app->singleton(ReportExportService::class);
    }

    public function boot(): void
    {
        // Required for Filament/Livewire under XAMPP subdirectory installs
        if ($root = config('app.url')) {
            URL::forceRootUrl($root);
        }

        Batch::observe(BatchObserver::class);
    }
}
