<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Zaad = 'zaad';
    case Edahab = 'edahab';
    case BankTransfer = 'bank_transfer';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Zaad => 'Zaad',
            self::Edahab => 'eDahab',
            self::BankTransfer => 'Bank Transfer',
            self::Other => 'Other',
        };
    }
}
