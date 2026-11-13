<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique;

/**
 * Ligne de facture (groupe EN 16931 BG-25). Les montants et quantités sont des chaînes décimales
 * (« 12.50 ») : aucune perte de précision liée aux nombres à virgule flottante.
 */
final class Ligne
{
    public function __construct(
        /** Identifiant de la ligne (BT-126). */
        public readonly ?string $id = null,
        /** Désignation de l'article ou du service (BT-153). */
        public readonly ?string $name = null,
        /** Quantité facturée (BT-129). */
        public readonly ?string $quantity = null,
        /** Unité de la quantité, code UN/ECE Rec 20 (BT-130). */
        public readonly ?string $unitCode = null,
        /** Prix unitaire net hors taxe (BT-146). */
        public readonly ?string $netPrice = null,
        /** Montant net de la ligne hors taxe (BT-131). */
        public readonly ?string $netAmount = null,
        /** Catégorie de TVA, code UNTDID 5305 (BT-151). */
        public readonly ?string $vatCategory = null,
        /** Taux de TVA en pourcentage (BT-152). */
        public readonly ?string $vatRate = null,
    ) {}
}
