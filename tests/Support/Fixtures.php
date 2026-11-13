<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Tests\Support;

/**
 * Accès aux jeux d'essai fabriqués du dossier tests/fixtures/.
 */
final class Fixtures
{
    public static function path(string $relative): string
    {
        return __DIR__ . '/../fixtures/' . $relative;
    }

    public static function read(string $relative): string
    {
        $content = file_get_contents(self::path($relative));
        if ($content === false) {
            throw new \RuntimeException('Jeu d\'essai introuvable : ' . $relative);
        }

        return $content;
    }
}
