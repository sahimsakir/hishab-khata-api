<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class Money
{
    // API money arrives as a decimal STRING; storage and arithmetic use integer paisa.
    public static function parse(string $value): int
    {
        if (! preg_match('/^(0|[1-9]\d{0,9})(?:\.(\d{1,2}))?$/D', $value, $m)) {
            throw ValidationException::withMessages(['amount' => 'Use a positive BDT amount with up to two decimal places.']);
        }

        return ((int) $m[1] * 100) + (int) str_pad($m[2] ?? '', 2, '0');
    }
}
