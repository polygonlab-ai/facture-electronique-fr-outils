<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique;

/**
 * Levée quand un PDF ne peut pas être lu comme un PDF Factur-X : fichier qui n'est pas un PDF,
 * PDF chiffré, aucun fichier factur-x.xml joint, flux compressé illisible ou trop volumineux.
 */
final class InvalidPdf extends \RuntimeException implements FactureElectroniqueException
{
    public static function notAPdf(): self
    {
        return new self('Le fichier n\'est pas un PDF (en-tête « %PDF- » absent).');
    }

    public static function encrypted(): self
    {
        return new self('Le PDF est chiffré : ses fichiers joints ne peuvent pas être lus.');
    }

    public static function noFacturXAttachment(): self
    {
        return new self('Aucun fichier factur-x.xml n\'est joint à ce PDF.');
    }

    public static function missingStream(string $reference): self
    {
        return new self(sprintf('Le fichier joint factur-x.xml est déclaré, mais son contenu (objet %s) est introuvable dans le PDF.', $reference));
    }

    public static function unsupportedFilter(string $filter): self
    {
        return new self(sprintf('Un flux du PDF est compressé avec un filtre non pris en charge (%s) : seul FlateDecode est lu.', $filter));
    }

    public static function unsupportedPredictor(int $predictor): self
    {
        return new self(sprintf('Un flux du PDF utilise un prédicteur non pris en charge (%d).', $predictor));
    }

    public static function corruptedStream(): self
    {
        return new self('Un flux compressé du PDF est illisible (données FlateDecode corrompues).');
    }

    public static function tooLarge(int $limit): self
    {
        return new self(sprintf('Un flux décompressé du PDF dépasse la taille autorisée (%d octets).', $limit));
    }

    public static function notCii(string $detail): self
    {
        return new self(sprintf('Le fichier factur-x.xml joint n\'est pas une facture CII lisible : %s', $detail));
    }

    public static function unreadableFile(string $path): self
    {
        return new self(sprintf('Le fichier « %s » est introuvable ou illisible.', $path));
    }
}
