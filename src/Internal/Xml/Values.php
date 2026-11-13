<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Internal\Xml;

use PolygonLab\FactureElectronique\InvalidDocument;

/**
 * Conversion des valeurs lues : montants décimaux et dates.
 *
 * @internal
 */
final class Values
{
    /**
     * Montant ou quantité en chaîne décimale normalisée (« +12.50 » devient « 12.50 »).
     *
     * @throws InvalidDocument si la valeur n'est pas un nombre décimal
     */
    public static function decimal(?string $value, string $term): ?string
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/\A([+-]?)(\d+(?:\.\d+)?|\.\d+)\z/', $value, $matches) !== 1) {
            throw InvalidDocument::invalidAmount($value, $term);
        }
        $number = str_starts_with($matches[2], '.') ? '0' . $matches[2] : $matches[2];

        return ($matches[1] === '-' ? '-' : '') . $number;
    }

    /**
     * Date au format AAAA-MM-JJ (UBL).
     *
     * @throws InvalidDocument si la date n'existe pas ou n'a pas ce format
     */
    public static function isoDate(?string $value, string $term): ?\DateTimeImmutable
    {
        return self::date($value, 'Y-m-d', '/\A\d{4}-\d{2}-\d{2}\z/', $term);
    }

    /**
     * Date au format 102 de l'UNTDID 2379, AAAAMMJJ (CII).
     *
     * @throws InvalidDocument si la date n'existe pas ou n'a pas ce format
     */
    public static function date102(?string $value, string $term): ?\DateTimeImmutable
    {
        return self::date($value, 'Ymd', '/\A\d{8}\z/', $term);
    }

    private static function date(?string $value, string $format, string $pattern, string $term): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $date = preg_match($pattern, $value) === 1
            ? \DateTimeImmutable::createFromFormat('!' . $format, $value, new \DateTimeZone('UTC'))
            : false;
        if ($date === false || $date->format($format) !== $value) {
            throw InvalidDocument::invalidDate($value, $term);
        }

        return $date;
    }
}
