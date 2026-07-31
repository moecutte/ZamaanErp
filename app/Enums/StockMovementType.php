<?php

namespace App\Enums;

enum StockMovementType: string
{
    case PurchaseIn = 'purchase_in';
    case SaleOut = 'sale_out';
    case WastageOut = 'wastage_out';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::PurchaseIn => 'Purchase In',
            self::SaleOut => 'Sale Out',
            self::WastageOut => 'Wastage Out',
            self::Adjustment => 'Adjustment',
        };
    }
}
