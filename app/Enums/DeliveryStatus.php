<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InTransit => 'In Transit',
            self::Delivered => 'Delivered',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }
}
