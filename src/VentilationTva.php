<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique;

/**
 * Ventilation de la TVA par catégorie et par taux (groupe EN 16931 BG-23).
 */
final class VentilationTva
{
    public function __construct(
        /** Catégorie de TVA, code UNTDID 5305 : S, Z, E, AE, K, G, O, L, M (BT-118). */
        public readonly ?string $category = null,
        /** Taux en pourcentage (BT-119). */
        public readonly ?string $rate = null,
        /** Base hors taxe (BT-116). */
        public readonly ?string $taxableAmount = null,
        /** Montant de TVA (BT-117). */
        public readonly ?string $taxAmount = null,
        /** Motif d'exonération en clair (BT-120). */
        public readonly ?string $exemptionReason = null,
    ) {}
}
