<?php

namespace App\Enums;

enum StorageLocation: string
{
    case Frozen = 'frozen';
    case Chilled = 'chilled';
    case Fresh = 'fresh';

    public function label(): string
    {
        return match ($this) {
            self::Frozen => 'Frozen',
            self::Chilled => 'Chilled',
            self::Fresh => 'Fresh',
        };
    }
}
