<?php

namespace App\Support;

class Money
{
    public static function code(): string
    {
        return (string) config('zamaan.currency.code', 'SOS');
    }

    public static function label(): string
    {
        return (string) config('zamaan.currency.label', 'Slsh');
    }

    public static function decimals(): int
    {
        return (int) config('zamaan.currency.decimals', 0);
    }

    public static function format(mixed $amount, ?int $decimals = null): string
    {
        $decimals ??= self::decimals();

        return number_format((float) $amount, $decimals).' '.self::label();
    }

    public static function prefix(): string
    {
        return self::label().' ';
    }
}
