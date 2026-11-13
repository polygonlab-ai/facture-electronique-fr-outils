<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique;

/**
 * Profil Factur-X d'une facture, déduit de l'identifiant de spécification (BT-24) du XML CII.
 */
enum FacturXProfile: string
{
    case Minimum = 'MINIMUM';
    case BasicWl = 'BASIC WL';
    case Basic = 'BASIC';
    case En16931 = 'EN 16931';
    case Extended = 'EXTENDED';

    /**
     * Rend le profil d'un identifiant de spécification Factur-X ; null s'il n'en est pas un.
     */
    public static function fromGuidelineId(?string $guidelineId): ?self
    {
        foreach (self::cases() as $profile) {
            if ($guidelineId !== null && trim($guidelineId) === $profile->guidelineId()) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * Identifiant de spécification (BT-24) écrit dans le XML pour ce profil.
     */
    public function guidelineId(): string
    {
        return match ($this) {
            self::Minimum => 'urn:factur-x.eu:1p0:minimum',
            self::BasicWl => 'urn:factur-x.eu:1p0:basicwl',
            self::Basic => 'urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:basic',
            self::En16931 => 'urn:cen.eu:en16931:2017',
            self::Extended => 'urn:cen.eu:en16931:2017#conformant#urn:factur-x.eu:1p0:extended',
        };
    }

    /**
     * Vrai si le profil porte une facture complète au sens d'EN 16931 (BASIC, EN 16931, EXTENDED).
     * MINIMUM et BASIC WL ne portent pas les lignes de facture.
     */
    public function hasLines(): bool
    {
        return $this !== self::Minimum && $this !== self::BasicWl;
    }
}
