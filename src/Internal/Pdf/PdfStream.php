<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Internal\Pdf;

/**
 * Flux PDF : son dictionnaire et ses octets bruts, encore compressés.
 *
 * @internal
 */
final class PdfStream implements PdfValue
{
    public function __construct(
        public readonly PdfDictionary $dictionary,
        public readonly string $raw,
    ) {}
}
