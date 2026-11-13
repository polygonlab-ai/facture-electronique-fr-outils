<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Internal\Pdf;

/**
 * @internal
 */
final class PdfDictionary implements PdfValue
{
    /**
     * @param array<string, PdfValue> $entries clé sans barre oblique => valeur
     */
    public function __construct(
        public readonly array $entries,
    ) {}

    public function get(string $key): ?PdfValue
    {
        return $this->entries[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->entries[$key]);
    }

    /**
     * Vrai si l'entrée est le nom donné, par exemple /Type /ObjStm.
     */
    public function isName(string $key, string $name): bool
    {
        $value = $this->get($key);

        return $value instanceof PdfName && $value->name === $name;
    }

    public function integer(string $key): ?int
    {
        $value = $this->get($key);

        return $value instanceof PdfNumber && \is_int($value->value) ? $value->value : null;
    }
}
