<?php

namespace App\Enums;

enum UnitType: string
{
    case WeightKg = 'weight_kg';
    case WeightG = 'weight_g';
    case Piece = 'piece';
    case Box = 'box';

    public function label(): string
    {
        return match ($this) {
            self::WeightKg => 'Kilogram (kg)',
            self::WeightG => 'Gram (g)',
            self::Piece => 'Piece',
            self::Box => 'Box',
        };
    }
}
