<?php

namespace App\Support;

use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\InflectorFactory;
use Doctrine\Inflector\Language;

/**
 * Textos de limite e desconto mostrados ao cliente (site, tablet, contrato) e no catálogo.
 * A unidade é cadastrada no singular ("consulta"); o plural sai daqui, em português.
 */
final class BenefitText
{
    private static ?Inflector $inflector = null;

    /** "1 consulta", "2 consultas", "3 avaliações". */
    public static function quantity(int $quantity, string $unit): string
    {
        return $quantity.' '.($quantity === 1 ? $unit : self::inflector()->pluralize($unit));
    }

    /** "20%", "12,5%": sem zeros à direita e com vírgula decimal. */
    public static function percent(string|int|float $value): string
    {
        $formatted = rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');

        return $formatted.'%';
    }

    private static function inflector(): Inflector
    {
        return self::$inflector ??= InflectorFactory::createForLanguage(Language::PORTUGUESE)->build();
    }
}
