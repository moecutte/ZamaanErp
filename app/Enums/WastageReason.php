<?php

namespace App\Enums;

enum WastageReason: string
{
    case Expired = 'expired';
    case Damaged = 'damaged';
    case QualityReject = 'quality_reject';

    public function label(): string
    {
        return match ($this) {
            self::Expired => 'Expired',
            self::Damaged => 'Damaged',
            self::QualityReject => 'Quality Reject',
        };
    }
}
