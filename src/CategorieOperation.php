<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique;

/**
 * Catégorie de l'opération facturée, mention ajoutée par le décret n° 2022-1299 du 7 octobre 2022 :
 * livraison de biens, prestation de services, ou les deux.
 */
enum CategorieOperation: string
{
    /** Livraison de biens. */
    case LB = 'LB';

    /** Prestation de services. */
    case PS = 'PS';

    /** Livraison de biens et prestation de services. */
    case LBPS = 'LBPS';

    /**
     * Déduit la catégorie du « cadre de facturation » français porté par le terme BT-23
     * (type de processus métier) : un code qui commence par B (biens), S (services) ou M (mixte),
     * suivi d'un chiffre, par exemple « B1 », « S1 » ou « M1 ».
     *
     * Rend null pour toute autre valeur : la catégorie n'est jamais devinée.
     */
    public static function fromCadreDeFacturation(?string $code): ?self
    {
        if ($code === null || preg_match('/\A([BSM])[1-9]\z/', strtoupper(trim($code)), $matches) !== 1) {
            return null;
        }

        return match ($matches[1]) {
            'B' => self::LB,
            'S' => self::PS,
            default => self::LBPS,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::LB => 'livraison de biens',
            self::PS => 'prestation de services',
            self::LBPS => 'livraison de biens et prestation de services',
        };
    }

    /**
     * Vrai si l'opération comprend une livraison de biens (LB ou LBPS).
     */
    public function includesGoods(): bool
    {
        return $this !== self::PS;
    }
}
