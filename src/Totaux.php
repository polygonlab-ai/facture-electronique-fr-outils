<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique;

/**
 * Totaux de la facture (groupe EN 16931 BG-22), en chaînes décimales.
 */
final class Totaux
{
    public function __construct(
        /** Somme des montants nets des lignes (BT-106). */
        public readonly ?string $lineTotal = null,
        /** Total hors taxe (BT-109). */
        public readonly ?string $taxExclusive = null,
        /** Montant total de TVA, dans la devise de la facture (BT-110). */
        public readonly ?string $taxTotal = null,
        /** Total toutes taxes comprises (BT-112). */
        public readonly ?string $taxInclusive = null,
        /** Montant à payer (BT-115). */
        public readonly ?string $payable = null,
    ) {}
}
