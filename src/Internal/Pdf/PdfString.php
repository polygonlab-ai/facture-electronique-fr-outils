<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Internal\Pdf;

/**
 * Chaîne PDF (littérale ou hexadécimale), en octets bruts.
 *
 * @internal
 */
final class PdfString implements PdfValue
{
    public function __construct(
        public readonly string $bytes,
    ) {}

    /**
     * Chaîne de texte convertie en UTF-8 (ISO 32000-1, section « Text String Type ») : UTF-16BE si elle commence par
     * l'indicateur FE FF, UTF-8 si elle commence par EF BB BF, sinon octets lus en Latin-1
     * (identique à PDFDocEncoding pour les caractères ASCII).
     */
    public function text(): string
    {
        if (str_starts_with($this->bytes, "\xFE\xFF")) {
            return self::fromUtf16Be(substr($this->bytes, 2));
        }
        if (str_starts_with($this->bytes, "\xEF\xBB\xBF")) {
            return substr($this->bytes, 3);
        }

        $text = '';
        $length = \strlen($this->bytes);
        for ($i = 0; $i < $length; ++$i) {
            $byte = \ord($this->bytes[$i]);
            $text .= $byte < 0x80 ? $this->bytes[$i] : \chr(0xC0 | ($byte >> 6)) . \chr(0x80 | ($byte & 0x3F));
        }

        return $text;
    }

    private static function fromUtf16Be(string $bytes): string
    {
        $text = '';
        $length = \strlen($bytes) - (\strlen($bytes) % 2);
        for ($i = 0; $i < $length; $i += 2) {
            $unit = (\ord($bytes[$i]) << 8) | \ord($bytes[$i + 1]);
            if ($unit >= 0xD800 && $unit <= 0xDBFF && $i + 3 < $length) {
                $low = (\ord($bytes[$i + 2]) << 8) | \ord($bytes[$i + 3]);
                if ($low >= 0xDC00 && $low <= 0xDFFF) {
                    $unit = 0x10000 + (($unit - 0xD800) << 10) + ($low - 0xDC00);
                    $i += 2;
                }
            }
            $text .= self::utf8($unit);
        }

        return $text;
    }

    private static function utf8(int $codePoint): string
    {
        if ($codePoint >= 0xD800 && $codePoint <= 0xDFFF) {
            $codePoint = 0xFFFD; // Demi-indicateur isolé : caractère de remplacement.
        }

        return match (true) {
            $codePoint < 0x80 => \chr($codePoint),
            $codePoint < 0x800 => \chr(0xC0 | ($codePoint >> 6)) . \chr(0x80 | ($codePoint & 0x3F)),
            $codePoint < 0x10000 => \chr(0xE0 | ($codePoint >> 12)) . \chr(0x80 | (($codePoint >> 6) & 0x3F)) . \chr(0x80 | ($codePoint & 0x3F)),
            default => \chr(0xF0 | ($codePoint >> 18)) . \chr(0x80 | (($codePoint >> 12) & 0x3F)) . \chr(0x80 | (($codePoint >> 6) & 0x3F)) . \chr(0x80 | ($codePoint & 0x3F)),
        };
    }
}
