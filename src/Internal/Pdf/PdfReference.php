<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Internal\Pdf;

/**
 * Référence indirecte « numéro génération R ».
 *
 * @internal
 */
final class PdfReference implements PdfValue
{
    public function __construct(
        public readonly int $number,
        public readonly int $generation,
    ) {}

    public function toString(): string
    {
        return $this->number . ' ' . $this->generation . ' R';
    }
}
