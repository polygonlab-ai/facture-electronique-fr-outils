<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique;

/**
 * Mention manquante ou invalide relevée par ControleMentions.
 */
final class Violation
{
    public function __construct(
        /** Règle enfreinte ; son code stable est $rule->value. */
        public readonly Rule $rule,
        /** Message en français, prêt à afficher. */
        public readonly string $message,
        /** Date à partir de laquelle la donnée est attendue. */
        public readonly \DateTimeImmutable $applicableFrom,
        /** Identifiant de la ligne concernée (BT-126), pour une règle qui porte sur une ligne. */
        public readonly ?string $lineId = null,
    ) {}

    /**
     * Code stable, par exemple « SIREN_CLIENT_ABSENT ».
     */
    public function code(): string
    {
        return $this->rule->value;
    }
}
