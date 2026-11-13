<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique;

/**
 * XML CII extrait d'un PDF Factur-X, avec son profil.
 */
final class FacturXDocument
{
    public function __construct(
        /** Contenu du fichier factur-x.xml joint au PDF. */
        public readonly string $xml,
        /** Identifiant de spécification lu dans le XML (BT-24), tel quel. */
        public readonly ?string $guidelineId,
        /** Profil Factur-X ; null si l'identifiant n'est pas celui d'un profil connu. */
        public readonly ?FacturXProfile $profile,
    ) {}

    /**
     * Lit le XML comme une facture CII.
     *
     * @throws InvalidDocument si le XML ne peut pas être lu comme une facture
     */
    public function facture(): Facture
    {
        return (new LecteurCii())->read($this->xml);
    }
}
