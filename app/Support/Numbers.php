<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Números legíveis e não sequenciais para propostas, contratos e assinaturas.
 */
final class Numbers
{
    public static function generate(string $prefix, string $table, string $column = 'number'): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        for ($i = 0; $i < 10; $i++) {
            $suffix = '';
            for ($j = 0; $j < 6; $j++) {
                $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $number = $prefix.'-'.BusinessTime::now()->format('ym').'-'.$suffix;
            if (! DB::table($table)->where($column, $number)->exists()) {
                return $number;
            }
        }

        return $prefix.'-'.Str::upper(Str::ulid());
    }
}
