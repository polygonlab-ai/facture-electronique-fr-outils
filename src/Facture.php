<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique;

/**
 * Modèle neutre et immuable d'une facture, indépendant de la syntaxe (CII ou UBL).
 *
 * Les champs suivent les termes métier de la norme EN 16931 (identifiants BT-… et BG-… cités en
 * commentaire). Tout champ peut manquer : la lecture ne juge pas, c'est ControleMentions qui dit ce
 * qui manque à une date donnée.
 */
final class Facture
{
    /** Code UNTDID 1001 d'un avoir (BT-3). */
    public const TYPE_CREDIT_NOTE = '381';

    /** Phrase de la mention de l'option pour le paiement de la taxe d'après les débits. */
    public const OPTION_DEBITS_MENTION = 'option pour le paiement de la taxe d\'après les débits';

    /**
     * Catégorie de l'opération : donnée explicitement, ou déduite du cadre de facturation (BT-23).
     */
    public readonly ?CategorieOperation $categorieOperation;

    /**
     * @param list<string>         $notes          Notes de la facture (BT-22)
     * @param list<Ligne>          $lines          Lignes (BG-25)
     * @param list<VentilationTva> $vatBreakdowns Ventilation de la TVA (BG-23)
     */
    public function __construct(
        /** Syntaxe d'origine. */
        public readonly Format $format,
        /** Numéro de facture (BT-1). */
        public readonly ?string $number = null,
        /** Date d'émission (BT-2). */
        public readonly ?\DateTimeImmutable $issueDate = null,
        /** Type de document, code UNTDID 1001 : 380 facture, 381 avoir (BT-3). */
        public readonly ?string $typeCode = null,
        /** Devise de la facture, code ISO 4217 (BT-5). */
        public readonly ?string $currency = null,
        /** Code de la date d'exigibilité de la TVA (BT-8). */
        public readonly ?string $vatPointDateCode = null,
        public readonly array $notes = [],
        /** Type de processus métier, ou cadre de facturation français (BT-23). */
        public readonly ?string $businessProcess = null,
        /** Identifiant de spécification, ou profil (BT-24). */
        public readonly ?string $specificationId = null,
        /** Vendeur (BG-4). */
        public readonly Partie $seller = new Partie(),
        /** Client (BG-7). */
        public readonly Partie $buyer = new Partie(),
        /** Adresse de livraison (BG-15). */
        public readonly ?Adresse $deliveryAddress = null,
        /** Date de livraison effective (BT-72). */
        public readonly ?\DateTimeImmutable $deliveryDate = null,
        public readonly array $lines = [],
        public readonly array $vatBreakdowns = [],
        /** Totaux (BG-22). */
        public readonly Totaux $totaux = new Totaux(),
        ?CategorieOperation $categorieOperation = null,
    ) {
        $this->categorieOperation = $categorieOperation ?? CategorieOperation::fromCadreDeFacturation($businessProcess);
    }

    /**
     * Copie de la facture avec la catégorie d'opération donnée, pour un flux qui ne la porte pas en BT-23.
     */
    public function withCategorieOperation(CategorieOperation $categorie): self
    {
        return new self(
            $this->format,
            $this->number,
            $this->issueDate,
            $this->typeCode,
            $this->currency,
            $this->vatPointDateCode,
            $this->notes,
            $this->businessProcess,
            $this->specificationId,
            $this->seller,
            $this->buyer,
            $this->deliveryAddress,
            $this->deliveryDate,
            $this->lines,
            $this->vatBreakdowns,
            $this->totaux,
            $categorie,
        );
    }

    /**
     * Vrai si le document est un avoir (BT-3 = 381).
     */
    public function isCreditNote(): bool
    {
        return $this->typeCode === self::TYPE_CREDIT_NOTE;
    }

    /**
     * Vrai si la facture porte l'option pour le paiement de la taxe d'après les débits : soit le code
     * BT-8 désigne la date d'émission de la facture (5 en CII, 3 en UBL), soit une note (BT-22)
     * reprend la phrase « option pour le paiement de la taxe d'après les débits ».
     */
    public function hasOptionDebitsMention(): bool
    {
        $invoiceDateCode = $this->format === Format::Cii ? '5' : '3';
        if ($this->vatPointDateCode === $invoiceDateCode) {
            return true;
        }
        foreach ($this->notes as $note) {
            if (str_contains(self::normalize($note), self::normalize(self::OPTION_DEBITS_MENTION))) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $text): string
    {
        // Apostrophes typographiques, majuscules accentuées de la phrase : sans dépendre de mbstring.
        $text = str_replace(["\u{2019}", "\u{2018}", 'É', 'È'], ['\'', '\'', 'é', 'è'], $text);

        return (string) preg_replace('/\s+/u', ' ', strtolower($text));
    }
}
