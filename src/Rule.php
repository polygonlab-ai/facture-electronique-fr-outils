<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique;

/**
 * Règle de contrôle des mentions obligatoires. La valeur de chaque cas est son code stable, à utiliser
 * dans un programme : il ne changera pas d'une version à l'autre.
 *
 * Chaque règle porte la date à partir de laquelle la donnée est attendue, selon la page « données de
 * facture » de l'administration fiscale (impots.gouv.fr) : 1er septembre 2026 pour la plupart des
 * données, 1er septembre 2027 pour les données des lignes et l'adresse de livraison.
 */
enum Rule: string
{
    /** Date à partir de laquelle la plupart des données sont attendues. */
    public const DATE_2026 = '2026-09-01';

    /** Date à partir de laquelle les données des lignes et l'adresse de livraison sont attendues. */
    public const DATE_2027 = '2027-09-01';

    // Mentions nouvelles du décret n° 2022-1299 du 7 octobre 2022.
    case SirenClientAbsent = 'SIREN_CLIENT_ABSENT';
    case SirenClientInvalide = 'SIREN_CLIENT_INVALIDE';
    case AdresseLivraisonIncomplete = 'ADRESSE_LIVRAISON_INCOMPLETE';
    case CategorieOperationAbsente = 'CATEGORIE_OPERATION_ABSENTE';
    case MentionOptionDebitsAbsente = 'MENTION_OPTION_DEBITS_ABSENTE';

    // Mentions déjà obligatoires avant la réforme.
    case NumeroAbsent = 'NUMERO_ABSENT';
    case DateAbsente = 'DATE_ABSENTE';
    case DeviseAbsente = 'DEVISE_ABSENTE';
    case NomVendeurAbsent = 'NOM_VENDEUR_ABSENT';
    case AdresseVendeurIncomplete = 'ADRESSE_VENDEUR_INCOMPLETE';
    case TvaVendeurAbsente = 'TVA_VENDEUR_ABSENTE';
    case TvaVendeurInvalide = 'TVA_VENDEUR_INVALIDE';
    case NomClientAbsent = 'NOM_CLIENT_ABSENT';
    case AdresseClientIncomplete = 'ADRESSE_CLIENT_INCOMPLETE';
    case TvaClientAbsente = 'TVA_CLIENT_ABSENTE';
    case LignesAbsentes = 'LIGNES_ABSENTES';
    case DesignationAbsente = 'DESIGNATION_ABSENTE';
    case QuantiteAbsente = 'QUANTITE_ABSENTE';
    case PrixUnitaireAbsent = 'PRIX_UNITAIRE_ABSENT';
    case TauxTvaAbsent = 'TAUX_TVA_ABSENT';
    case VentilationTvaAbsente = 'VENTILATION_TVA_ABSENTE';
    case BaseTvaAbsente = 'BASE_TVA_ABSENTE';
    case MontantTvaAbsent = 'MONTANT_TVA_ABSENT';
    case TotalHtAbsent = 'TOTAL_HT_ABSENT';
    case TotalTtcAbsent = 'TOTAL_TTC_ABSENT';

    /**
     * Date à partir de laquelle la donnée est attendue sur la facture (« attendue à compter de »).
     */
    public function applicableFrom(): \DateTimeImmutable
    {
        $date = match ($this) {
            self::AdresseLivraisonIncomplete, self::LignesAbsentes, self::DesignationAbsente,
            self::QuantiteAbsente, self::PrixUnitaireAbsent => self::DATE_2027,
            default => self::DATE_2026,
        };

        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }

    /**
     * Vrai pour les quatre mentions ajoutées par le décret n° 2022-1299 du 7 octobre 2022 (SIREN du client,
     * adresse de livraison, catégorie de l'opération, option sur les débits).
     */
    public function isReformMention(): bool
    {
        return match ($this) {
            self::SirenClientAbsent, self::SirenClientInvalide, self::AdresseLivraisonIncomplete,
            self::CategorieOperationAbsente, self::MentionOptionDebitsAbsente => true,
            default => false,
        };
    }

    /**
     * Mention contrôlée, en français.
     */
    public function label(): string
    {
        return match ($this) {
            self::SirenClientAbsent, self::SirenClientInvalide => 'SIREN du client',
            self::AdresseLivraisonIncomplete => 'Adresse de livraison des biens, si elle diffère de l\'adresse de facturation',
            self::CategorieOperationAbsente => 'Catégorie de l\'opération : biens (LB), services (PS) ou les deux (LBPS)',
            self::MentionOptionDebitsAbsente => 'Mention « option pour le paiement de la taxe d\'après les débits »',
            self::NumeroAbsent => 'Numéro de facture',
            self::DateAbsente => 'Date d\'émission',
            self::DeviseAbsente => 'Devise',
            self::NomVendeurAbsent => 'Nom du vendeur',
            self::AdresseVendeurIncomplete => 'Adresse du vendeur',
            self::TvaVendeurAbsente, self::TvaVendeurInvalide => 'Numéro de TVA intracommunautaire du vendeur',
            self::NomClientAbsent => 'Nom du client',
            self::AdresseClientIncomplete => 'Adresse du client',
            self::TvaClientAbsente => 'Numéro de TVA du client (opération intracommunautaire ou autoliquidée)',
            self::LignesAbsentes => 'Lignes de la facture',
            self::DesignationAbsente => 'Désignation des biens ou services',
            self::QuantiteAbsente => 'Quantité',
            self::PrixUnitaireAbsent => 'Prix unitaire hors taxe',
            self::TauxTvaAbsent => 'Taux de TVA',
            self::VentilationTvaAbsente => 'Ventilation de la TVA par taux',
            self::BaseTvaAbsente => 'Total hors taxe par taux',
            self::MontantTvaAbsent => 'Montant de la TVA',
            self::TotalHtAbsent => 'Total hors taxe',
            self::TotalTtcAbsent => 'Total toutes taxes comprises',
        };
    }

    /**
     * Termes EN 16931 qui portent la mention.
     */
    public function terms(): string
    {
        return match ($this) {
            self::SirenClientAbsent, self::SirenClientInvalide => 'BT-47',
            self::AdresseLivraisonIncomplete => 'BG-15',
            self::CategorieOperationAbsente => 'BT-23',
            self::MentionOptionDebitsAbsente => 'BT-8 ou BT-22',
            self::NumeroAbsent => 'BT-1',
            self::DateAbsente => 'BT-2',
            self::DeviseAbsente => 'BT-5',
            self::NomVendeurAbsent => 'BT-27',
            self::AdresseVendeurIncomplete => 'BG-5',
            self::TvaVendeurAbsente, self::TvaVendeurInvalide => 'BT-31',
            self::NomClientAbsent => 'BT-44',
            self::AdresseClientIncomplete => 'BG-8',
            self::TvaClientAbsente => 'BT-48',
            self::LignesAbsentes => 'BG-25',
            self::DesignationAbsente => 'BT-153',
            self::QuantiteAbsente => 'BT-129',
            self::PrixUnitaireAbsent => 'BT-146',
            self::TauxTvaAbsent => 'BT-152, BT-119',
            self::VentilationTvaAbsente => 'BG-23',
            self::BaseTvaAbsente => 'BT-116',
            self::MontantTvaAbsent => 'BT-110, BT-117',
            self::TotalHtAbsent => 'BT-109',
            self::TotalTtcAbsent => 'BT-112',
        };
    }

    /**
     * Texte qui impose la mention.
     */
    public function source(): string
    {
        return match (true) {
            $this === self::SirenClientAbsent, $this === self::SirenClientInvalide => 'CGI, annexe II, art. 242 nonies A ; code de commerce, art. R.123-221',
            $this->isReformMention() => 'CGI, annexe II, art. 242 nonies A (décret n° 2022-1299 du 7 octobre 2022)',
            $this === self::DeviseAbsente, $this === self::LignesAbsentes, $this === self::VentilationTvaAbsente => 'Norme EN 16931',
            default => 'CGI, annexe II, art. 242 nonies A',
        };
    }
}
