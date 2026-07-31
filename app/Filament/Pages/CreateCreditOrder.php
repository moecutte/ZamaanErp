<?php

namespace App\Filament\Pages;

use App\Enums\CustomerType;
use App\Enums\SalesChannel;
use App\Models\Customer;

class CreateCreditOrder extends CreateSalesOrderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Sales Order';
    protected static ?string $title = 'New Sales Order';
    protected static ?int $navigationSort = 2;
    protected static ?string $slug = 'sales/credit-order';

    protected function customerTypes(): array
    {
        return [CustomerType::Restaurant, CustomerType::Retailer];
    }

    protected function resolveChannel(Customer $customer): SalesChannel
    {
        return SalesChannel::SalesOrder;
    }

    protected function autoConfirm(): bool
    {
        return false;
    }

    protected function allowsCredit(): bool
    {
        return true;
    }
}
