<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Internal\Pdf;

use PolygonLab\FactureElectronique\InvalidPdf;

/**
 * Objets d'un fichier PDF, lus par un parcours séquentiel du fichier.
 *
 * Le parcours ne dépend pas de la table des références croisées : il trouve chaque objet
 * « n g obj … endobj », y compris dans un fichier mis à jour par ajouts successifs (la dernière
 * définition d'un objet l'emporte) ou dont la table est abîmée. Les objets rangés dans des flux
 * d'objets (/Type /ObjStm, PDF 1.5 et plus) sont lus eux aussi.
 *
 * @internal
 */
final class PdfDocument
{
    /** Nombre maximal de références suivies l'une après l'autre. */
    private const MAX_INDIRECTIONS = 32;

    /** Profondeur maximale explorée pour trouver les dictionnaires imbriqués. */
    private const MAX_COLLECT_DEPTH = 32;

    /**
     * @param array<int, PdfValue> $objects    numéro d'objet => valeur
     * @param list<PdfDictionary>  $trailers   dictionnaires de queue (« trailer » et flux /Type /XRef)
     */
    private function __construct(
        private readonly array $objects,
        private readonly array $trailers,
    ) {}

    /**
     * @throws InvalidPdf si le fichier n'est pas un PDF ou si un flux d'objets ne peut pas être décodé
     */
    public static function parse(string $data, StreamDecoder $decoder): self
    {
        $header = strpos(substr($data, 0, 1024), '%PDF-');
        if ($header === false) {
            throw InvalidPdf::notAPdf();
        }

        $objects = [];
        $trailers = [];
        $objectStreams = [];
        $offset = $header;
        while (preg_match('/(?<![0-9])([0-9]{1,10})[\x00\t\n\x0C\r ]+([0-9]{1,5})[\x00\t\n\x0C\r ]+obj\b/', $data, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1];
            $parser = new Parser($data, $start + \strlen($match[0][0]));
            try {
                $value = $parser->parseValue();
                if ($value instanceof PdfDictionary && $parser->nextIsKeyword('stream')) {
                    [$value, $end] = self::readStream($data, $value, $parser);
                    $parser = new Parser($data, $end);
                }
            } catch (SyntaxError) {
                // Faux positif ou objet abîmé : on cherche le suivant juste après.
                $offset = $start + 1;
                continue;
            }
            $number = (int) $match[1][0];
            $objects[$number] = $value;
            if ($value instanceof PdfStream && $value->dictionary->isName('Type', 'ObjStm')) {
                $objectStreams[] = $value;
            }
            if ($value instanceof PdfStream && $value->dictionary->isName('Type', 'XRef')) {
                $trailers[] = $value->dictionary;
            }
            $offset = max($parser->position(), $start + 1);
        }

        if (preg_match_all('/trailer[\x00\t\n\x0C\r ]*(?=<<)/', $data, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$keyword, $position]) {
                try {
                    $trailer = (new Parser($data, $position + \strlen($keyword)))->parseValue();
                } catch (SyntaxError) {
                    continue;
                }
                if ($trailer instanceof PdfDictionary) {
                    $trailers[] = $trailer;
                }
            }
        }

        $document = new self($objects, $trailers);
        foreach ($objectStreams as $stream) {
            $document = $document->withObjectStream($stream, $decoder);
        }

        return $document;
    }

    public function get(int $number): ?PdfValue
    {
        return $this->objects[$number] ?? null;
    }

    /**
     * Suit les références indirectes jusqu'à une valeur directe ; null si l'objet n'existe pas.
     */
    public function resolve(?PdfValue $value): ?PdfValue
    {
        for ($i = 0; $value instanceof PdfReference && $i < self::MAX_INDIRECTIONS; ++$i) {
            $value = $this->get($value->number);
        }

        return $value instanceof PdfReference ? null : $value;
    }

    /**
     * Vrai si un dictionnaire de queue déclare un chiffrement (/Encrypt).
     */
    public function isEncrypted(): bool
    {
        foreach ($this->trailers as $trailer) {
            if ($trailer->has('Encrypt')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tous les dictionnaires du document, y compris ceux imbriqués dans d'autres valeurs, dans l'ordre
     * des numéros d'objet.
     *
     * @return list<PdfDictionary>
     */
    public function dictionaries(): array
    {
        $objects = $this->objects;
        ksort($objects);
        $found = [];
        foreach ($objects as $object) {
            self::collect($object, $found, 0);
        }

        return $found;
    }

    /**
     * @param list<PdfDictionary> $found
     */
    private static function collect(PdfValue $value, array &$found, int $depth): void
    {
        if ($depth > self::MAX_COLLECT_DEPTH) {
            return;
        }
        if ($value instanceof PdfStream) {
            $value = $value->dictionary;
        }
        if ($value instanceof PdfDictionary) {
            $found[] = $value;
            foreach ($value->entries as $entry) {
                self::collect($entry, $found, $depth + 1);
            }
        } elseif ($value instanceof PdfArray) {
            foreach ($value->items as $item) {
                self::collect($item, $found, $depth + 1);
            }
        }
    }

    /**
     * Lit le flux qui suit un dictionnaire : longueur /Length si elle est directe et cohérente,
     * sinon recherche du mot-clé « endstream ».
     *
     * @return array{PdfStream, int} le flux et la position après « endstream »
     */
    private static function readStream(string $data, PdfDictionary $dictionary, Parser $parser): array
    {
        $start = $parser->position() + \strlen('stream');
        if (substr($data, $start, 2) === "\r\n") {
            $start += 2;
        } elseif (($data[$start] ?? '') === "\n" || ($data[$start] ?? '') === "\r") {
            ++$start;
        }

        $length = $dictionary->integer('Length');
        if ($length !== null && $length >= 0 && $start + $length <= \strlen($data)) {
            $after = new Parser($data, $start + $length);
            if ($after->nextIsKeyword('endstream')) {
                return [new PdfStream($dictionary, substr($data, $start, $length)), $after->position() + \strlen('endstream')];
            }
        }

        $end = strpos($data, 'endstream', $start);
        if ($end === false) {
            throw new SyntaxError('Flux non terminé.');
        }
        $raw = substr($data, $start, $end - $start);
        if (str_ends_with($raw, "\r\n")) {
            $raw = substr($raw, 0, -2);
        } elseif (str_ends_with($raw, "\n") || str_ends_with($raw, "\r")) {
            $raw = substr($raw, 0, -1);
        }

        return [new PdfStream($dictionary, $raw), $end + \strlen('endstream')];
    }

    /**
     * Ajoute les objets d'un flux d'objets (ISO 32000-1, § 7.5.7). Un objet déjà défini directement
     * dans le fichier garde sa définition directe.
     */
    private function withObjectStream(PdfStream $stream, StreamDecoder $decoder): self
    {
        $count = $stream->dictionary->integer('N');
        $first = $stream->dictionary->integer('First');
        if ($count === null || $first === null || $count < 0 || $first < 0) {
            return $this;
        }

        $content = $decoder->decode($stream, $this);
        $header = new Parser($content);
        $objects = $this->objects;
        for ($i = 0; $i < $count; ++$i) {
            try {
                $number = $header->parseValue();
                $offset = $header->parseValue();
                if (!$number instanceof PdfNumber || !$offset instanceof PdfNumber || !\is_int($number->value) || !\is_int($offset->value)) {
                    break;
                }
                $value = (new Parser($content, $first + $offset->value))->parseValue();
            } catch (SyntaxError) {
                break;
            }
            $objects[$number->value] ??= $value;
        }

        return new self($objects, $this->trailers);
    }
}
