<?php

namespace App\Support;

/**
 * Valores monetários sempre em centavos inteiros; nunca ponto flutuante.
 */
final class Money
{
    public static function format(?int $cents): string
    {
        if ($cents === null) {
            return '—';
        }
        $negative = $cents < 0;
        $cents = abs($cents);
        $value = number_format(intdiv($cents, 100), 0, ',', '.').','.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').'R$ '.$value;
    }

    /** Converte "1.234,56", "1234.56" ou "1234" para centavos sem usar float. */
    public static function parse(?string $input): ?int
    {
        if ($input === null) {
            return null;
        }
        $s = preg_replace('/[^\d,\.]/', '', trim($input));
        if ($s === '') {
            return null;
        }
        if (str_contains($s, ',')) {
            $s = str_replace('.', '', $s);
            [$int, $dec] = array_pad(explode(',', $s, 2), 2, '0');
        } elseif (preg_match('/^\d+\.\d{1,2}$/', $s)) {
            [$int, $dec] = explode('.', $s, 2);
        } else {
            $int = str_replace('.', '', $s);
            $dec = '0';
        }
        $dec = substr(str_pad($dec, 2, '0'), 0, 2);

        return ((int) $int) * 100 + (int) $dec;
    }

    public static function input(?int $cents): string
    {
        return $cents === null ? '' : number_format(intdiv($cents, 100), 0, '', '').','.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
