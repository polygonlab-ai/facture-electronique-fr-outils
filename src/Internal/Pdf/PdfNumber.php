<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Internal\Pdf;

/**
 * @internal
 */
final class PdfNumber implements PdfValue
{
    public function __construct(
        public readonly int|float $value,
    ) {}
}
