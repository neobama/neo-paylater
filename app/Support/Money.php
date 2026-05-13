<?php

namespace App\Support;

class Money
{
    public static function normalize(int | float | string | null $value): int
    {
        if ($value === null) {
            return 0;
        }

        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_float($value)) {
            return max(0, (int) round($value));
        }

        $digits = preg_replace('/[^\d]/', '', $value);

        return (int) ($digits ?: 0);
    }

    public static function format(int | float | string | null $value): string
    {
        return 'Rp ' . number_format(self::normalize($value), 0, ',', '.');
    }
}
