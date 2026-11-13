<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Tests\Support;

/**
 * Construit des PDF minimaux pour les tests, octet par octet, sans bibliothèque externe.
 *
 * Deux modes d'écriture : objets classiques avec table de références croisées, ou objets rangés dans
 * un flux d'objets (/Type /ObjStm) avec un flux de références croisées (/Type /XRef), comme les PDF 1.5
 * et plus.
 */
final class PdfBuilder
{
    /** @var array<int, string> numéro => contenu de l'objet (sans « n 0 obj » ni « endobj ») */
    private array $objects = [];

    private int $next = 1;

    public function reserve(): int
    {
        return $this->next++;
    }

    public function add(string $body): int
    {
        $number = $this->reserve();
        $this->objects[$number] = $body;

        return $number;
    }

    public function set(int $number, string $body): void
    {
        $this->objects[$number] = $body;
    }

    /**
     * Objet flux : dictionnaire (sans /Length, ajouté ici sauf si $length est fourni) puis données.
     */
    public static function stream(string $dictionary, string $data, ?string $length = null): string
    {
        return sprintf("<< %s /Length %s >>\nstream\n%s\nendstream", $dictionary, $length ?? (string) \strlen($data), $data);
    }

    /**
     * Chaîne de texte PDF en UTF-16BE, écrite en hexadécimal avec l'indicateur FE FF.
     */
    public static function utf16Hex(string $text): string
    {
        $hex = 'FEFF';
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            // Caractères du plan multilingue de base (1 à 3 octets UTF-8) : une unité UTF-16.
            $codePoint = match (\strlen($char)) {
                1 => \ord($char),
                2 => ((\ord($char[0]) & 0x1F) << 6) | (\ord($char[1]) & 0x3F),
                default => ((\ord($char[0]) & 0x0F) << 12) | ((\ord($char[1]) & 0x3F) << 6) | (\ord($char[2]) & 0x3F),
            };
            $hex .= sprintf('%04X', $codePoint);
        }

        return '<' . $hex . '>';
    }

    /**
     * @param list<int> $inObjectStream numéros des objets (non flux) à ranger dans un flux d'objets
     */
    public function build(int $root, ?int $info = null, array $inObjectStream = [], string $trailerExtra = ''): string
    {
        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        ksort($this->objects);

        if ($inObjectStream === []) {
            foreach ($this->objects as $number => $body) {
                $offsets[$number] = \strlen($pdf);
                $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $number, $body);
            }
            $size = $this->objects === [] ? 1 : max(array_keys($this->objects)) + 1;
            $xref = \strlen($pdf);
            $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", $size);
            for ($i = 1; $i < $size; ++$i) {
                $pdf .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
            }
            $pdf .= sprintf("trailer\n<< /Size %d /Root %d 0 R%s%s >>\nstartxref\n%d\n%%%%EOF\n", $size, $root, $info !== null ? sprintf(' /Info %d 0 R', $info) : '', $trailerExtra, $xref);

            return $pdf;
        }

        // Flux d'objets : en-tête « numéro décalage … » puis les objets.
        $header = '';
        $content = '';
        $index = [];
        foreach ($inObjectStream as $position => $number) {
            $header .= sprintf('%d %d ', $number, \strlen($content));
            $content .= $this->objects[$number] . "\n";
            $index[$number] = $position;
        }
        $objectStream = $this->reserve();
        $data = (string) gzcompress($header . $content, 9);
        $this->objects[$objectStream] = self::stream(sprintf('/Type /ObjStm /N %d /First %d /Filter /FlateDecode', \count($inObjectStream), \strlen($header)), $data);

        foreach ($this->objects as $number => $body) {
            if (isset($index[$number])) {
                continue;
            }
            $offsets[$number] = \strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $number, $body);
        }

        $xrefNumber = $this->reserve();
        $size = $xrefNumber + 1;
        $offsets[$xrefNumber] = \strlen($pdf);
        $rows = pack('CNn', 0, 0, 65535);
        for ($i = 1; $i < $size; ++$i) {
            $rows .= match (true) {
                isset($index[$i]) => pack('CNn', 2, $objectStream, $index[$i]),
                isset($offsets[$i]) => pack('CNn', 1, $offsets[$i], 0),
                default => pack('CNn', 0, 0, 65535),
            };
        }
        $rows = (string) gzcompress($rows, 9);
        $pdf .= sprintf(
            "%d 0 obj\n%s\nendobj\nstartxref\n%d\n%%%%EOF\n",
            $xrefNumber,
            self::stream(sprintf('/Type /XRef /Size %d /W [1 4 2] /Root %d 0 R%s%s /Filter /FlateDecode', $size, $root, $info !== null ? sprintf(' /Info %d 0 R', $info) : '', $trailerExtra), $rows),
            $offsets[$xrefNumber],
        );

        return $pdf;
    }
}
