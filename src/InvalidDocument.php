<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique;

/**
 * Levée quand un document XML ne peut pas être lu comme une facture : XML mal formé, DTD présente,
 * élément racine inattendu, montant ou date illisible.
 *
 * Le message, en français, dit précisément ce qui ne va pas.
 */
final class InvalidDocument extends \RuntimeException implements FactureElectroniqueException
{
    public static function empty(): self
    {
        return new self('Le document XML est vide.');
    }

    public static function malformed(string $detail): self
    {
        return new self(sprintf('Le document XML est mal formé : %s.', $detail));
    }

    public static function doctype(): self
    {
        return new self('Le document XML contient une déclaration DOCTYPE : elle est refusée par sécurité (entités externes).');
    }

    public static function unexpectedRoot(string $expected, string $found): self
    {
        return new self(sprintf('Le document n\'est pas %s (élément racine trouvé : « %s »).', $expected, $found));
    }

    public static function invalidAmount(string $value, string $term): self
    {
        return new self(sprintf('Le montant « %s » (%s) n\'est pas un nombre décimal.', $value, $term));
    }

    public static function invalidDate(string $value, string $term): self
    {
        return new self(sprintf('La date « %s » (%s) n\'est pas une date valide.', $value, $term));
    }

    public static function unreadableFile(string $path): self
    {
        return new self(sprintf('Le fichier « %s » est introuvable ou illisible.', $path));
    }
}
