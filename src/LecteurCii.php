<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique;

use PolygonLab\FactureElectronique\Internal\Xml\SafeXml;
use PolygonLab\FactureElectronique\Internal\Xml\Values;

/**
 * Lit une facture UN/CEFACT Cross Industry Invoice (CII D16B), la syntaxe embarquée dans les PDF
 * Factur-X, et rend une Facture.
 *
 * Le lecteur ne valide le document ni contre les schémas XSD ni contre les règles Schematron : il lit
 * les termes présents, sans juger de leur cohérence.
 */
final class LecteurCii
{
    public const NS_RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
    public const NS_RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
    public const NS_UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';

    private const NAMESPACES = ['rsm' => self::NS_RSM, 'ram' => self::NS_RAM, 'udt' => self::NS_UDT];

    private const TRANSACTION = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction';
    private const AGREEMENT = self::TRANSACTION . '/ram:ApplicableHeaderTradeAgreement';
    private const DELIVERY = self::TRANSACTION . '/ram:ApplicableHeaderTradeDelivery';
    private const SETTLEMENT = self::TRANSACTION . '/ram:ApplicableHeaderTradeSettlement';
    private const SUMMATION = self::SETTLEMENT . '/ram:SpecifiedTradeSettlementHeaderMonetarySummation';

    /**
     * @throws InvalidDocument si le XML n'est pas une facture CII lisible
     */
    public function read(string $xml): Facture
    {
        $doc = SafeXml::load($xml, self::NAMESPACES);
        $root = $doc->root();
        if ($root->namespaceURI !== self::NS_RSM || $root->localName !== 'CrossIndustryInvoice') {
            throw InvalidDocument::unexpectedRoot('une facture CII (CrossIndustryInvoice)', $root->localName ?? $root->nodeName);
        }

        $currency = $doc->text(self::SETTLEMENT . '/ram:InvoiceCurrencyCode');

        return new Facture(
            format: Format::Cii,
            // BT-1 : numéro de facture.
            number: $doc->text('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID'),
            // BT-2 : date d'émission, format 102.
            issueDate: Values::date102($doc->text('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString'), 'BT-2'),
            // BT-3 : type de document.
            typeCode: $doc->text('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode'),
            // BT-5 : devise de la facture.
            currency: $currency,
            // BT-8 : code de la date d'exigibilité de la TVA (UNTDID 2475 en CII).
            vatPointDateCode: $doc->text(self::SETTLEMENT . '/ram:ApplicableTradeTax/ram:DueDateTypeCode'),
            // BT-22 : notes.
            notes: $doc->texts('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IncludedNote/ram:Content'),
            // BT-23 : type de processus métier (cadre de facturation).
            businessProcess: $doc->text('/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID'),
            // BT-24 : identifiant de spécification (profil Factur-X).
            specificationId: $doc->text('/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID'),
            // BG-4 : vendeur.
            seller: $this->party($doc, self::AGREEMENT . '/ram:SellerTradeParty'),
            // BG-7 : client.
            buyer: $this->party($doc, self::AGREEMENT . '/ram:BuyerTradeParty'),
            // BG-15 : adresse de livraison.
            deliveryAddress: $this->address($doc, self::DELIVERY . '/ram:ShipToTradeParty/ram:PostalTradeAddress'),
            // BT-72 : date de livraison effective.
            deliveryDate: Values::date102($doc->text(self::DELIVERY . '/ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString'), 'BT-72'),
            lines: $this->lines($doc),
            vatBreakdowns: $this->vatBreakdowns($doc),
            totaux: new Totaux(
                // BT-106 : somme des montants nets des lignes.
                lineTotal: Values::decimal($doc->text(self::SUMMATION . '/ram:LineTotalAmount'), 'BT-106'),
                // BT-109 : total hors taxe.
                taxExclusive: Values::decimal($doc->text(self::SUMMATION . '/ram:TaxBasisTotalAmount'), 'BT-109'),
                // BT-110 : total de TVA dans la devise de la facture (CII peut en porter deux, BT-110 et BT-111).
                taxTotal: Values::decimal($this->taxTotal($doc, $currency), 'BT-110'),
                // BT-112 : total toutes taxes comprises.
                taxInclusive: Values::decimal($doc->text(self::SUMMATION . '/ram:GrandTotalAmount'), 'BT-112'),
                // BT-115 : montant à payer.
                payable: Values::decimal($doc->text(self::SUMMATION . '/ram:DuePayableAmount'), 'BT-115'),
            ),
        );
    }

    /**
     * @throws InvalidDocument si le fichier est illisible ou n'est pas une facture CII
     */
    public function readFile(string $path): Facture
    {
        $xml = is_file($path) ? @file_get_contents($path) : false;
        if ($xml === false) {
            throw InvalidDocument::unreadableFile($path);
        }

        return $this->read($xml);
    }

    private function party(SafeXml $doc, string $path): Partie
    {
        $party = $doc->element($path);
        if ($party === null) {
            return new Partie();
        }

        return new Partie(
            // BT-27, BT-44 : raison sociale.
            name: $doc->text('ram:Name', $party),
            // BG-5, BG-8 : adresse postale.
            address: $this->address($doc, 'ram:PostalTradeAddress', $party),
            // BT-30, BT-47 : identifiant légal (SIREN : schemeID 0002).
            legalId: $doc->text('ram:SpecifiedLegalOrganization/ram:ID', $party),
            legalIdScheme: $doc->attribute('ram:SpecifiedLegalOrganization/ram:ID', 'schemeID', $party),
            // BT-31, BT-48 : numéro de TVA (schemeID VA).
            vatNumber: $doc->text('ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]', $party),
        );
    }

    private function address(SafeXml $doc, string $path, ?\DOMNode $context = null): ?Adresse
    {
        $node = $doc->element($path, $context);
        if ($node === null) {
            return null;
        }

        return new Adresse(
            line1: $doc->text('ram:LineOne', $node),
            line2: $doc->text('ram:LineTwo', $node),
            city: $doc->text('ram:CityName', $node),
            postcode: $doc->text('ram:PostcodeCode', $node),
            countryCode: $doc->text('ram:CountryID', $node),
        );
    }

    /**
     * @return list<Ligne>
     */
    private function lines(SafeXml $doc): array
    {
        $lines = [];
        foreach ($doc->elements(self::TRANSACTION . '/ram:IncludedSupplyChainTradeLineItem') as $item) {
            $lines[] = new Ligne(
                // BT-126 : identifiant de ligne.
                id: $doc->text('ram:AssociatedDocumentLineDocument/ram:LineID', $item),
                // BT-153 : désignation.
                name: $doc->text('ram:SpecifiedTradeProduct/ram:Name', $item),
                // BT-129, BT-130 : quantité et unité.
                quantity: Values::decimal($doc->text('ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', $item), 'BT-129'),
                unitCode: $doc->attribute('ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', 'unitCode', $item),
                // BT-146 : prix unitaire net.
                netPrice: Values::decimal($doc->text('ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount', $item), 'BT-146'),
                // BT-131 : montant net de la ligne.
                netAmount: Values::decimal($doc->text('ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', $item), 'BT-131'),
                // BT-151, BT-152 : catégorie et taux de TVA de la ligne.
                vatCategory: $doc->text('ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode', $item),
                vatRate: Values::decimal($doc->text('ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent', $item), 'BT-152'),
            );
        }

        return $lines;
    }

    /**
     * @return list<VentilationTva>
     */
    private function vatBreakdowns(SafeXml $doc): array
    {
        $breakdowns = [];
        foreach ($doc->elements(self::SETTLEMENT . '/ram:ApplicableTradeTax') as $tax) {
            $breakdowns[] = new VentilationTva(
                // BT-118 : catégorie de TVA.
                category: $doc->text('ram:CategoryCode', $tax),
                // BT-119 : taux.
                rate: Values::decimal($doc->text('ram:RateApplicablePercent', $tax), 'BT-119'),
                // BT-116 : base hors taxe.
                taxableAmount: Values::decimal($doc->text('ram:BasisAmount', $tax), 'BT-116'),
                // BT-117 : montant de TVA.
                taxAmount: Values::decimal($doc->text('ram:CalculatedAmount', $tax), 'BT-117'),
                // BT-120 : motif d'exonération.
                exemptionReason: $doc->text('ram:ExemptionReason', $tax),
            );
        }

        return $breakdowns;
    }

    /**
     * BT-110 : le TaxTotalAmount dont la devise est celle de la facture, ou le seul présent.
     */
    private function taxTotal(SafeXml $doc, ?string $currency): ?string
    {
        if ($currency !== null) {
            $inCurrency = $doc->text(sprintf(self::SUMMATION . '/ram:TaxTotalAmount[@currencyID="%s"]', str_replace('"', '', $currency)));
            if ($inCurrency !== null) {
                return $inCurrency;
            }
        }

        return $doc->text(self::SUMMATION . '/ram:TaxTotalAmount');
    }
}
