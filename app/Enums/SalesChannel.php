<?php

namespace App\Enums;

enum SalesChannel: string
{
    case Pos = 'pos';
    case SalesOrder = 'sales_order';

    public function label(): string
    {
        return match ($this) {
            self::Pos => 'POS',
            self::SalesOrder => 'Sales Order',
        };
    }
}
