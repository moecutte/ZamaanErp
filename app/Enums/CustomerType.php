<?php

namespace App\Enums;

enum CustomerType: string
{
    case Household = 'household';
    case Restaurant = 'restaurant';
    case Retailer = 'retailer';

    public function label(): string
    {
        return match ($this) {
            self::Household => 'Household',
            self::Restaurant => 'Restaurant',
            self::Retailer => 'Retailer',
        };
    }
}
