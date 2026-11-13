<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Internal\Pdf;

/**
 * Erreur de syntaxe dans un objet PDF : l'objet est ignoré, la lecture continue.
 *
 * @internal
 */
final class SyntaxError extends \RuntimeException {}
