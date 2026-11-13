<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Internal\Pdf;

use PolygonLab\FactureElectronique\InvalidPdf;

/**
 * Décode les flux PDF : aucun filtre, ou FlateDecode (zlib, RFC 1950) sans prédicteur.
 *
 * La décompression se fait par morceaux et s'arrête dès que la taille autorisée est dépassée :
 * une « bombe de décompression » ne peut pas saturer la mémoire.
 *
 * @internal
 */
final class StreamDecoder
{
    private const CHUNK = 4096;

    public function __construct(
        private readonly int $maxBytes,
    ) {}

    /**
     * @throws InvalidPdf si un filtre n'est pas pris en charge, si les données sont corrompues ou trop volumineuses
     */
    public function decode(PdfStream $stream, PdfDocument $document): string
    {
        $filters = $this->asList($document->resolve($stream->dictionary->get('Filter')));
        $parameters = $this->asList($document->resolve($stream->dictionary->get('DecodeParms')));
        $data = $stream->raw;

        foreach ($filters as $index => $filter) {
            $filter = $document->resolve($filter);
            if (!$filter instanceof PdfName) {
                throw InvalidPdf::unsupportedFilter('filtre illisible');
            }
            if ($filter->name !== 'FlateDecode') {
                throw InvalidPdf::unsupportedFilter('/' . $filter->name);
            }
            $parameter = $document->resolve($parameters[$index] ?? null);
            $predictor = $parameter instanceof PdfDictionary ? ($parameter->integer('Predictor') ?? 1) : 1;
            if ($predictor > 1) {
                throw InvalidPdf::unsupportedPredictor($predictor);
            }
            $data = $this->inflate($data);
        }

        if (\strlen($data) > $this->maxBytes) {
            throw InvalidPdf::tooLarge($this->maxBytes);
        }

        return $data;
    }

    /**
     * @return list<PdfValue>
     */
    private function asList(?PdfValue $value): array
    {
        return match (true) {
            $value === null, $value === PdfKeyword::Null => [],
            $value instanceof PdfArray => $value->items,
            default => [$value],
        };
    }

    private function inflate(string $data): string
    {
        $context = inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($context === false) {
            throw InvalidPdf::corruptedStream(); // @codeCoverageIgnore
        }

        $output = '';
        $length = \strlen($data);
        set_error_handler(static fn(): bool => true);
        try {
            for ($offset = 0; $offset < $length; $offset += self::CHUNK) {
                $last = $offset + self::CHUNK >= $length;
                $chunk = inflate_add($context, substr($data, $offset, self::CHUNK), $last ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
                if ($chunk === false) {
                    throw InvalidPdf::corruptedStream();
                }
                $output .= $chunk;
                if (\strlen($output) > $this->maxBytes) {
                    throw InvalidPdf::tooLarge($this->maxBytes);
                }
                if (inflate_get_status($context) === ZLIB_STREAM_END) {
                    break;
                }
            }
        } finally {
            restore_error_handler();
        }
        if ($length === 0 || inflate_get_status($context) !== ZLIB_STREAM_END) {
            throw InvalidPdf::corruptedStream();
        }

        return $output;
    }
}
