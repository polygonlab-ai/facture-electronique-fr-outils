<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique;

/**
 * Adresse postale d'une partie ou de livraison (groupes EN 16931 BG-5, BG-8 et BG-15).
 *
 * Objet immuable. Chaque champ peut manquer : la lecture ne rejette rien, c'est le contrôle des
 * mentions qui signale une adresse incomplète.
 */
final class Adresse
{
    public function __construct(
        /** Ligne 1 (BT-35, BT-50, BT-75). */
        public readonly ?string $line1 = null,
        /** Ligne 2 (BT-36, BT-51, BT-76). */
        public readonly ?string $line2 = null,
        /** Ville (BT-37, BT-52, BT-77). */
        public readonly ?string $city = null,
        /** Code postal (BT-38, BT-53, BT-78). */
        public readonly ?string $postcode = null,
        /** Code pays ISO 3166-1 alpha-2 (BT-40, BT-55, BT-80). */
        public readonly ?string $countryCode = null,
    ) {}

    /**
     * Vrai si l'adresse comporte une ligne, un code postal, une ville et un pays.
     */
    public function isComplete(): bool
    {
        return $this->line1 !== null && $this->postcode !== null && $this->city !== null && $this->countryCode !== null;
    }

    /**
     * Vrai si aucun champ n'est renseigné.
     */
    public function isEmpty(): bool
    {
        return $this->line1 === null && $this->line2 === null && $this->city === null
            && $this->postcode === null && $this->countryCode === null;
    }

    /**
     * Vrai si les deux adresses désignent le même lieu (comparaison sans tenir compte de la casse ni des espaces).
     */
    public function sameAs(self $other): bool
    {
        return self::key($this) === self::key($other);
    }

    /**
     * Forme lisible sur une ligne : « 1 rue de l'Exemple, 69000 Lyon, FR ».
     */
    public function format(): string
    {
        $cityLine = trim(($this->postcode ?? '') . ' ' . ($this->city ?? ''));

        return implode(', ', array_filter(
            [$this->line1, $this->line2, $cityLine, $this->countryCode],
            static fn(?string $part): bool => $part !== null && $part !== '',
        ));
    }

    private static function key(self $address): string
    {
        $parts = [$address->line1, $address->line2, $address->postcode, $address->city, $address->countryCode];

        return strtolower((string) preg_replace('/\s+/u', ' ', implode('|', array_map(
            static fn(?string $part): string => trim($part ?? ''),
            $parts,
        ))));
    }
}
