<?php

namespace App\Enums;

enum StockMovementType: string
{
    case PurchaseIn = 'purchase_in';
    case SaleOut = 'sale_out';
    case WastageOut = 'wastage_out';
    case Adjustment = 'adjustment';
    case ProcessingOut = 'processing_out';
    case ProcessingIn = 'processing_in';

    public function label(): string
    {
        return match ($this) {
            self::PurchaseIn => 'Purchase In',
            self::SaleOut => 'Sale Out',
            self::WastageOut => 'Wastage Out',
            self::Adjustment => 'Adjustment',
            self::ProcessingOut => 'Processing Out',
            self::ProcessingIn => 'Processing In',
        };
    }
}
