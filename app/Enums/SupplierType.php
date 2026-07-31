<?php

namespace App\Enums;

enum SupplierType: string
{
    case Fisherman = 'fisherman';
    case Company = 'company';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Fisherman => 'Fisherman',
            self::Company => 'Company',
            self::Import => 'Import',
        };
    }
}
