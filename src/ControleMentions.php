<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique;

/**
 * Vérifie les mentions obligatoires d'une facture à une date de référence et rend la liste des manques.
 *
 * Chaque règle a sa propre date d'application (Rule::applicableFrom()), reprise de la page « données de
 * facture » de l'administration fiscale : 1er septembre 2026 pour la plupart des données, 1er septembre
 * 2027 pour les données des lignes et l'adresse de livraison. Ces dates ne dépendent pas de la taille de
 * l'entreprise. Une règle dont la date est postérieure à la date de référence n'est pas signalée.
 *
 * Le contrôle porte sur la présence des mentions, pas sur l'exactitude des calculs.
 */
final class ControleMentions
{
    /** Catégories de TVA (UNTDID 5305) d'une opération intracommunautaire ou autoliquidée. */
    private const BUYER_VAT_CATEGORIES = ['K', 'AE'];

    /** Catégories de TVA sans taux : hors champ. */
    private const NO_RATE_CATEGORIES = ['O'];

    /** Catégories de TVA d'une facture qui ne collecte aucune taxe : exonérée ou hors champ. */
    private const NO_TAX_CATEGORIES = ['E', 'O'];

    public function __construct(
        /** Vrai si le vendeur a opté pour le paiement de la taxe d'après les débits. */
        private readonly bool $optionDebits = false,
    ) {}

    public function check(Facture $facture, \DateTimeInterface $referenceDate): ControlReport
    {
        $reference = new \DateTimeImmutable($referenceDate->format('Y-m-d'), new \DateTimeZone('UTC'));
        $violations = [];
        $add = static function (Rule $rule, string $message, ?string $lineId = null) use (&$violations, $reference): void {
            $from = $rule->applicableFrom();
            if ($from <= $reference) {
                $violations[] = new Violation($rule, $message, $from, $lineId);
            }
        };

        $this->checkReformMentions($facture, $add);
        $this->checkDocument($facture, $add);
        $this->checkParties($facture, $add);
        $this->checkLines($facture, $add);
        $this->checkVat($facture, $add);

        return new ControlReport($reference, $violations);
    }

    /**
     * @param \Closure(Rule, string, ?string=): void $add
     */
    private function checkReformMentions(Facture $facture, \Closure $add): void
    {
        $buyer = $facture->buyer;
        $buyerCountry = $buyer->address?->countryCode;
        // Le SIREN n'existe que pour une entreprise française : un client établi hors de France n'en a pas.
        if ($buyerCountry === null || strtoupper($buyerCountry) === 'FR') {
            if ($buyer->hasInvalidSiren()) {
                $add(Rule::SirenClientInvalide, sprintf('Le SIREN du client (BT-47) « %s » n\'est pas valide.', $buyer->legalId));
            } elseif ($buyer->siren() === null) {
                $add(Rule::SirenClientAbsent, 'Le SIREN du client (BT-47, schéma 0002) est absent.');
            }
        }

        // Adresse de livraison : attendue seulement si elle diffère de l'adresse du client.
        $delivery = $facture->deliveryAddress;
        $sameAsBuyer = $delivery !== null && $buyer->address !== null && $delivery->sameAs($buyer->address);
        if ($delivery !== null && !$delivery->isEmpty() && !$sameAsBuyer && !$delivery->isComplete()) {
            $add(Rule::AdresseLivraisonIncomplete, 'L\'adresse de livraison (BG-15) est incomplète : ligne, code postal, ville et pays sont attendus.');
        }

        $categorie = $facture->categorieOperation;
        if ($categorie === null) {
            $add(Rule::CategorieOperationAbsente, 'La catégorie de l\'opération (livraison de biens, prestation de services ou les deux) est absente : cadre de facturation attendu en BT-23, par exemple B1, S1 ou M1.');
        }

        // L'option pour la taxe d'après les débits ne concerne que les prestations de services.
        if ($this->optionDebits && $categorie !== CategorieOperation::LB && !$facture->hasOptionDebitsMention()) {
            $add(Rule::MentionOptionDebitsAbsente, 'La mention « option pour le paiement de la taxe d\'après les débits » est absente (BT-8 ou note BT-22), alors que le vendeur a opté.');
        }
    }

    /**
     * @param \Closure(Rule, string, ?string=): void $add
     */
    private function checkDocument(Facture $facture, \Closure $add): void
    {
        if ($facture->number === null) {
            $add(Rule::NumeroAbsent, 'Le numéro de la facture (BT-1) est absent.');
        }
        if ($facture->issueDate === null) {
            $add(Rule::DateAbsente, 'La date d\'émission de la facture (BT-2) est absente.');
        }
        if ($facture->currency === null) {
            $add(Rule::DeviseAbsente, 'La devise de la facture (BT-5) est absente.');
        }
    }

    /**
     * @param \Closure(Rule, string, ?string=): void $add
     */
    private function checkParties(Facture $facture, \Closure $add): void
    {
        $seller = $facture->seller;
        if ($seller->name === null) {
            $add(Rule::NomVendeurAbsent, 'Le nom du vendeur (BT-27) est absent.');
        }
        if ($seller->address === null || !$seller->address->isComplete()) {
            $add(Rule::AdresseVendeurIncomplete, 'L\'adresse du vendeur (BG-5) est absente ou incomplète : ligne, code postal, ville et pays sont attendus.');
        }
        if ($seller->vatNumber === null) {
            if ($this->collectsTax($facture)) {
                $add(Rule::TvaVendeurAbsente, 'Le numéro de TVA intracommunautaire du vendeur (BT-31) est absent.');
            }
        } elseif (str_starts_with(strtoupper(trim($seller->vatNumber)), 'FR') && $seller->numeroTvaFrancais() === null) {
            $add(Rule::TvaVendeurInvalide, sprintf('Le numéro de TVA du vendeur (BT-31) « %s » n\'est pas un numéro français valide.', $seller->vatNumber));
        }

        $buyer = $facture->buyer;
        if ($buyer->name === null) {
            $add(Rule::NomClientAbsent, 'Le nom du client (BT-44) est absent.');
        }
        if ($buyer->address === null || !$buyer->address->isComplete()) {
            $add(Rule::AdresseClientIncomplete, 'L\'adresse du client (BG-8) est absente ou incomplète : ligne, code postal, ville et pays sont attendus.');
        }
        if ($buyer->vatNumber === null && array_intersect($this->vatCategories($facture), self::BUYER_VAT_CATEGORIES) !== []) {
            $add(Rule::TvaClientAbsente, 'Le numéro de TVA du client (BT-48) est absent alors que l\'opération est intracommunautaire ou autoliquidée (catégorie K ou AE).');
        }
    }

    /**
     * @param \Closure(Rule, string, ?string=): void $add
     */
    private function checkLines(Facture $facture, \Closure $add): void
    {
        if ($facture->lines === []) {
            $add(Rule::LignesAbsentes, 'La facture ne comporte aucune ligne (BG-25) : désignation, quantité et prix ne peuvent pas être contrôlés.');

            return;
        }

        foreach ($facture->lines as $index => $line) {
            $label = sprintf('Ligne %s', $line->id ?? (string) ($index + 1));
            if ($line->name === null) {
                $add(Rule::DesignationAbsente, $label . ' : la désignation (BT-153) est absente.', $line->id);
            }
            if ($line->quantity === null) {
                $add(Rule::QuantiteAbsente, $label . ' : la quantité (BT-129) est absente.', $line->id);
            }
            if ($line->netPrice === null) {
                $add(Rule::PrixUnitaireAbsent, $label . ' : le prix unitaire hors taxe (BT-146) est absent.', $line->id);
            }
            if ($line->vatRate === null && !in_array($line->vatCategory, self::NO_RATE_CATEGORIES, true)) {
                $add(Rule::TauxTvaAbsent, $label . ' : le taux de TVA (BT-152) est absent.', $line->id);
            }
        }
    }

    /**
     * @param \Closure(Rule, string, ?string=): void $add
     */
    private function checkVat(Facture $facture, \Closure $add): void
    {
        if ($facture->vatBreakdowns === []) {
            $add(Rule::VentilationTvaAbsente, 'La ventilation de la TVA par taux (BG-23) est absente.');
        }
        foreach ($facture->vatBreakdowns as $index => $breakdown) {
            $label = sprintf('Ventilation de TVA n° %d', $index + 1);
            if ($breakdown->rate === null && !in_array($breakdown->category, self::NO_RATE_CATEGORIES, true)) {
                $add(Rule::TauxTvaAbsent, $label . ' : le taux (BT-119) est absent.');
            }
            if ($breakdown->taxableAmount === null) {
                $add(Rule::BaseTvaAbsente, $label . ' : la base hors taxe (BT-116) est absente.');
            }
            if ($breakdown->taxAmount === null) {
                $add(Rule::MontantTvaAbsent, $label . ' : le montant de TVA (BT-117) est absent.');
            }
        }

        $totals = $facture->totaux;
        if ($totals->taxExclusive === null) {
            $add(Rule::TotalHtAbsent, 'Le total hors taxe (BT-109) est absent.');
        }
        if ($totals->taxTotal === null) {
            $add(Rule::MontantTvaAbsent, 'Le montant total de la TVA (BT-110) est absent.');
        }
        if ($totals->taxInclusive === null) {
            $add(Rule::TotalTtcAbsent, 'Le total toutes taxes comprises (BT-112) est absent.');
        }
    }

    /**
     * Catégories de TVA de la facture : celles de la ventilation, à défaut celles des lignes.
     *
     * @return list<string>
     */
    private function vatCategories(Facture $facture): array
    {
        $categories = array_map(static fn(VentilationTva $breakdown): ?string => $breakdown->category, $facture->vatBreakdowns);
        if ($categories === []) {
            $categories = array_map(static fn(Ligne $line): ?string => $line->vatCategory, $facture->lines);
        }

        return array_values(array_unique(array_map('strtoupper', array_filter($categories, static fn(?string $category): bool => $category !== null))));
    }

    /**
     * Vrai si la facture collecte une taxe : au moins une catégorie de TVA autre qu'exonérée ou hors champ,
     * ou aucune catégorie connue.
     */
    private function collectsTax(Facture $facture): bool
    {
        $categories = $this->vatCategories($facture);

        return $categories === [] || array_diff($categories, self::NO_TAX_CATEGORIES) !== [];
    }
}
