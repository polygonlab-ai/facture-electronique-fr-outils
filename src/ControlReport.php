<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique;

/**
 * Résultat d'un contrôle des mentions : la liste des manques à la date de référence.
 */
final class ControlReport
{
    /**
     * @param list<Violation> $violations
     */
    public function __construct(
        /** Date de référence du contrôle. */
        public readonly \DateTimeImmutable $referenceDate,
        public readonly array $violations,
    ) {}

    /**
     * Vrai si aucune mention ne manque.
     */
    public function isCompliant(): bool
    {
        return $this->violations === [];
    }

    /**
     * Codes des manques, sans doublon, dans l'ordre du contrôle.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return array_values(array_unique(array_map(
            static fn(Violation $violation): string => $violation->code(),
            $this->violations,
        )));
    }

    public function has(Rule $rule): bool
    {
        foreach ($this->violations as $violation) {
            if ($violation->rule === $rule) {
                return true;
            }
        }

        return false;
    }
}
