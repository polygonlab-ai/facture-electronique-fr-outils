<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Internal\Pdf;

/**
 * Mots-clés true, false et null.
 *
 * @internal
 */
enum PdfKeyword: string implements PdfValue
{
    case True = 'true';
    case False = 'false';
    case Null = 'null';
}
