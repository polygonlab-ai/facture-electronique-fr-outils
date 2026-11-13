<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Internal\Pdf;

/**
 * @internal
 */
final class PdfArray implements PdfValue
{
    /**
     * @param list<PdfValue> $items
     */
    public function __construct(
        public readonly array $items,
    ) {}
}
