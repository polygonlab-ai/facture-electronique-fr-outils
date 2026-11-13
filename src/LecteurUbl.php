<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique;

use PolygonLab\FactureElectronique\Internal\Xml\SafeXml;
use PolygonLab\FactureElectronique\Internal\Xml\Values;

/**
 * Lit une facture OASIS UBL 2.1 (document Invoice ou CreditNote) et rend une Facture.
 *
 * Le lecteur ne valide le document ni contre les schémas XSD ni contre les règles Schematron : il lit
 * les termes présents, sans juger de leur cohérence.
 */
final class LecteurUbl
{
    public const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    public const NS_CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';
    public const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    public const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    /**
     * @throws InvalidDocument si le XML n'est pas une facture ou un avoir UBL lisible
     */
    public function read(string $xml): Facture
    {
        $doc = SafeXml::load($xml, ['cac' => self::NS_CAC, 'cbc' => self::NS_CBC]);
        $root = $doc->root();
        $isInvoice = $root->namespaceURI === self::NS_INVOICE && $root->localName === 'Invoice';
        $isCreditNote = $root->namespaceURI === self::NS_CREDIT_NOTE && $root->localName === 'CreditNote';
        if (!$isInvoice && !$isCreditNote) {
            throw InvalidDocument::unexpectedRoot('une facture UBL (Invoice ou CreditNote)', $root->localName ?? $root->nodeName);
        }
        // Les éléments propres à l'avoir portent un autre nom : CreditNoteTypeCode, CreditNoteLine, CreditedQuantity.
        $kind = $isInvoice ? 'Invoice' : 'CreditNote';

        return new Facture(
            format: Format::Ubl,
            // BT-1 : numéro de facture.
            number: $doc->text('cbc:ID', $root),
            // BT-2 : date d'émission.
            issueDate: Values::isoDate($doc->text('cbc:IssueDate', $root), 'BT-2'),
            // BT-3 : type de document.
            typeCode: $doc->text('cbc:' . $kind . 'TypeCode', $root),
            // BT-5 : devise de la facture.
            currency: $doc->text('cbc:DocumentCurrencyCode', $root),
            // BT-8 : code de la date d'exigibilité de la TVA (UNTDID 2005 en UBL).
            vatPointDateCode: $doc->text('cac:InvoicePeriod/cbc:DescriptionCode', $root),
            // BT-22 : notes.
            notes: $doc->texts('cbc:Note', $root),
            // BT-23 : type de processus métier (cadre de facturation).
            businessProcess: $doc->text('cbc:ProfileID', $root),
            // BT-24 : identifiant de spécification.
            specificationId: $doc->text('cbc:CustomizationID', $root),
            // BG-4 : vendeur.
            seller: $this->party($doc, $doc->element('cac:AccountingSupplierParty/cac:Party', $root)),
            // BG-7 : client.
            buyer: $this->party($doc, $doc->element('cac:AccountingCustomerParty/cac:Party', $root)),
            // BG-15 : adresse de livraison.
            deliveryAddress: $this->address($doc, $doc->element('cac:Delivery/cac:DeliveryLocation/cac:Address', $root)),
            // BT-72 : date de livraison effective.
            deliveryDate: Values::isoDate($doc->text('cac:Delivery/cbc:ActualDeliveryDate', $root), 'BT-72'),
            lines: $this->lines($doc, $root, $kind),
            vatBreakdowns: $this->vatBreakdowns($doc, $root),
            totaux: new Totaux(
                // BT-106 : somme des montants nets des lignes.
                lineTotal: Values::decimal($doc->text('cac:LegalMonetaryTotal/cbc:LineExtensionAmount', $root), 'BT-106'),
                // BT-109 : total hors taxe.
                taxExclusive: Values::decimal($doc->text('cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount', $root), 'BT-109'),
                // BT-110 : total de TVA, porté par le TaxTotal qui a une ventilation (BT-111 n'en a pas).
                taxTotal: Values::decimal($this->taxTotal($doc, $root), 'BT-110'),
                // BT-112 : total toutes taxes comprises.
                taxInclusive: Values::decimal($doc->text('cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount', $root), 'BT-112'),
                // BT-115 : montant à payer.
                payable: Values::decimal($doc->text('cac:LegalMonetaryTotal/cbc:PayableAmount', $root), 'BT-115'),
            ),
        );
    }

    /**
     * @throws InvalidDocument si le fichier est illisible ou n'est pas une facture UBL
     */
    public function readFile(string $path): Facture
    {
        $xml = is_file($path) ? @file_get_contents($path) : false;
        if ($xml === false) {
            throw InvalidDocument::unreadableFile($path);
        }

        return $this->read($xml);
    }

    private function party(SafeXml $doc, ?\DOMElement $party): Partie
    {
        if ($party === null) {
            return new Partie();
        }

        return new Partie(
            // BT-27, BT-44 : raison sociale (à défaut, le nom commercial BT-28, BT-45).
            name: $doc->text('cac:PartyLegalEntity/cbc:RegistrationName', $party) ?? $doc->text('cac:PartyName/cbc:Name', $party),
            // BG-5, BG-8 : adresse postale.
            address: $this->address($doc, $doc->element('cac:PostalAddress', $party)),
            // BT-30, BT-47 : identifiant légal (SIREN : schemeID 0002).
            legalId: $doc->text('cac:PartyLegalEntity/cbc:CompanyID', $party),
            legalIdScheme: $doc->attribute('cac:PartyLegalEntity/cbc:CompanyID', 'schemeID', $party),
            // BT-31, BT-48 : numéro de TVA (régime fiscal VAT).
            vatNumber: $doc->text('cac:PartyTaxScheme[cac:TaxScheme/cbc:ID="VAT"]/cbc:CompanyID', $party),
        );
    }

    private function address(SafeXml $doc, ?\DOMElement $node): ?Adresse
    {
        if ($node === null) {
            return null;
        }

        return new Adresse(
            line1: $doc->text('cbc:StreetName', $node),
            line2: $doc->text('cbc:AdditionalStreetName', $node),
            city: $doc->text('cbc:CityName', $node),
            postcode: $doc->text('cbc:PostalZone', $node),
            countryCode: $doc->text('cac:Country/cbc:IdentificationCode', $node),
        );
    }

    /**
     * @return list<Ligne>
     */
    private function lines(SafeXml $doc, \DOMElement $root, string $kind): array
    {
        $quantity = $kind === 'Invoice' ? 'cbc:InvoicedQuantity' : 'cbc:CreditedQuantity';
        $lines = [];
        foreach ($doc->elements('cac:' . $kind . 'Line', $root) as $item) {
            $lines[] = new Ligne(
                // BT-126 : identifiant de ligne.
                id: $doc->text('cbc:ID', $item),
                // BT-153 : désignation.
                name: $doc->text('cac:Item/cbc:Name', $item),
                // BT-129, BT-130 : quantité et unité.
                quantity: Values::decimal($doc->text($quantity, $item), 'BT-129'),
                unitCode: $doc->attribute($quantity, 'unitCode', $item),
                // BT-146 : prix unitaire net.
                netPrice: Values::decimal($doc->text('cac:Price/cbc:PriceAmount', $item), 'BT-146'),
                // BT-131 : montant net de la ligne.
                netAmount: Values::decimal($doc->text('cbc:LineExtensionAmount', $item), 'BT-131'),
                // BT-151, BT-152 : catégorie et taux de TVA de la ligne.
                vatCategory: $doc->text('cac:Item/cac:ClassifiedTaxCategory/cbc:ID', $item),
                vatRate: Values::decimal($doc->text('cac:Item/cac:ClassifiedTaxCategory/cbc:Percent', $item), 'BT-152'),
            );
        }

        return $lines;
    }

    /**
     * @return list<VentilationTva>
     */
    private function vatBreakdowns(SafeXml $doc, \DOMElement $root): array
    {
        $breakdowns = [];
        foreach ($doc->elements('cac:TaxTotal/cac:TaxSubtotal', $root) as $subtotal) {
            $breakdowns[] = new VentilationTva(
                // BT-118 : catégorie de TVA.
                category: $doc->text('cac:TaxCategory/cbc:ID', $subtotal),
                // BT-119 : taux.
                rate: Values::decimal($doc->text('cac:TaxCategory/cbc:Percent', $subtotal), 'BT-119'),
                // BT-116 : base hors taxe.
                taxableAmount: Values::decimal($doc->text('cbc:TaxableAmount', $subtotal), 'BT-116'),
                // BT-117 : montant de TVA.
                taxAmount: Values::decimal($doc->text('cbc:TaxAmount', $subtotal), 'BT-117'),
                // BT-120 : motif d'exonération.
                exemptionReason: $doc->text('cac:TaxCategory/cbc:TaxExemptionReason', $subtotal),
            );
        }

        return $breakdowns;
    }

    /**
     * BT-110 : montant du TaxTotal qui porte la ventilation, ou à défaut du premier TaxTotal.
     */
    private function taxTotal(SafeXml $doc, \DOMElement $root): ?string
    {
        return $doc->text('cac:TaxTotal[cac:TaxSubtotal]/cbc:TaxAmount', $root)
            ?? $doc->text('cac:TaxTotal/cbc:TaxAmount', $root);
    }
}
