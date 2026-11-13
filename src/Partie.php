<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique;

use PolygonLab\IdentifiantsEntreprise\NumeroTvaIntracom;
use PolygonLab\IdentifiantsEntreprise\Siren;
use PolygonLab\IdentifiantsEntreprise\Siret;

/**
 * Partie à la facture : vendeur (BG-4) ou client (BG-7).
 *
 * L'identifiant légal (BT-30, BT-47) et le numéro de TVA (BT-31, BT-48) sont gardés tels que lus :
 * siren() et numeroTvaFrancais() les convertissent en objets valeur de identifiants-entreprise-fr,
 * ou rendent null si la valeur n'est pas un identifiant français valide.
 */
final class Partie
{
    /** Code ISO 6523 du SIREN. */
    public const SCHEME_SIREN = '0002';

    /** Code ISO 6523 du SIRET. */
    public const SCHEME_SIRET = '0009';

    public function __construct(
        /** Raison sociale (BT-27, BT-44). */
        public readonly ?string $name = null,
        /** Adresse postale (BG-5, BG-8). */
        public readonly ?Adresse $address = null,
        /** Identifiant légal tel que lu (BT-30, BT-47). */
        public readonly ?string $legalId = null,
        /** Code du schéma de l'identifiant légal (attribut schemeID), par exemple « 0002 » pour le SIREN. */
        public readonly ?string $legalIdScheme = null,
        /** Numéro de TVA tel que lu (BT-31, BT-48), français ou étranger. */
        public readonly ?string $vatNumber = null,
    ) {}

    /**
     * Le SIREN de la partie, lu depuis l'identifiant légal : schéma 0002 (SIREN), schéma 0009 (SIRET,
     * dont on garde les 9 premiers chiffres) ou, sans schéma, une valeur qui est un SIREN valide.
     * Rend null si l'identifiant est absent, d'un autre schéma ou invalide.
     */
    public function siren(): ?Siren
    {
        if ($this->legalId === null) {
            return null;
        }

        return match ($this->legalIdScheme) {
            self::SCHEME_SIREN, null => Siren::tryFromString($this->legalId),
            self::SCHEME_SIRET => Siret::tryFromString($this->legalId)?->siren(),
            default => null,
        };
    }

    /**
     * Vrai si un identifiant légal est présent avec le schéma SIREN ou SIRET, mais n'est pas valide.
     */
    public function hasInvalidSiren(): bool
    {
        return $this->legalId !== null
            && in_array($this->legalIdScheme, [self::SCHEME_SIREN, self::SCHEME_SIRET], true)
            && $this->siren() === null;
    }

    /**
     * Le numéro de TVA intracommunautaire, s'il est français et valide ; null sinon.
     */
    public function numeroTvaFrancais(): ?NumeroTvaIntracom
    {
        return $this->vatNumber === null ? null : NumeroTvaIntracom::tryFromString($this->vatNumber);
    }
}
