<?php

namespace App\Support;

/**
 * تنسيق القيم الرقمية للعرض (T084 — لا "62.0" في الـ JSON):
 *  - 74.0  → 74 (int)
 *  - 74.5  → 74.5 (float)
 *  - null  → null
 */
final class ScoreFormatter
{
    public static function format(?float $value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        $rounded = round((float) $value, 1);

        return $rounded == (int) $rounded ? (int) $rounded : $rounded;
    }
}
