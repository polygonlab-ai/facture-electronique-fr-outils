<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Internal\Pdf;

/**
 * Nom PDF, sans la barre oblique initiale et avec les séquences #xx décodées.
 *
 * @internal
 */
final class PdfName implements PdfValue
{
    public function __construct(
        public readonly string $name,
    ) {}
}
