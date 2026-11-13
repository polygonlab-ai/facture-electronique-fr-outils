<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Tests;

use PHPUnit\Framework\TestCase;
use PolygonLab\FactureElectronique\CategorieOperation;
use PolygonLab\FactureElectronique\Format;
use PolygonLab\FactureElectronique\InvalidDocument;
use PolygonLab\FactureElectronique\LecteurUbl;
use PolygonLab\FactureElectronique\Tests\Support\Fixtures;

final class LecteurUblTest extends TestCase
{
    public function testReadsTheCompleteInvoice(): void
    {
        $facture = (new LecteurUbl())->readFile(Fixtures::path('ubl/complete.xml'));

        self::assertSame(Format::Ubl, $facture->format);
        self::assertSame('F-2026-0107', $facture->number);
        self::assertSame('2026-10-20', $facture->issueDate?->format('Y-m-d'));
        self::assertSame('380', $facture->typeCode);
        self::assertSame('EUR', $facture->currency);
        self::assertNull($facture->vatPointDateCode);
        self::assertSame(['Facture de démonstration.'], $facture->notes);
        self::assertSame('B1', $facture->businessProcess);
        self::assertSame(CategorieOperation::LB, $facture->categorieOperation);
        self::assertSame('urn:cen.eu:en16931:2017', $facture->specificationId);
        self::assertFalse($facture->hasOptionDebitsMention());

        self::assertSame('Mobilier Fictif SAS', $facture->seller->name);
        self::assertSame('666666664', $facture->seller->siren()?->toString());
        self::assertSame('FR72666666664', $facture->seller->vatNumber);
        self::assertSame('5 place de la Démonstration, 44000 Nantes, FR', $facture->seller->address?->format());

        // Client identifié par son SIRET (schéma 0009) : le SIREN en est déduit.
        self::assertSame('Bureau Exemple SA', $facture->buyer->name);
        self::assertSame('0009', $facture->buyer->legalIdScheme);
        self::assertSame('777777772', $facture->buyer->siren()?->toString());
        self::assertSame('Bâtiment B', $facture->buyer->address?->line2);

        self::assertSame('12 rue du Dépôt, 59100 Roubaix, FR', $facture->deliveryAddress?->format());
        self::assertSame('2026-10-18', $facture->deliveryDate?->format('Y-m-d'));

        self::assertCount(2, $facture->lines);
        $line = $facture->lines[1];
        self::assertSame('2', $line->id);
        self::assertSame('Lampe de bureau', $line->name);
        self::assertSame('2', $line->quantity);
        self::assertSame('C62', $line->unitCode);
        self::assertSame('50.00', $line->netPrice);
        self::assertSame('100.00', $line->netAmount);
        self::assertSame('S', $line->vatCategory);
        self::assertSame('20', $line->vatRate);

        self::assertSame('700.00', $facture->vatBreakdowns[0]->taxableAmount);
        self::assertSame('140.00', $facture->vatBreakdowns[0]->taxAmount);
        self::assertSame('20', $facture->vatBreakdowns[0]->rate);
        self::assertSame('700.00', $facture->totaux->lineTotal);
        self::assertSame('700.00', $facture->totaux->taxExclusive);
        self::assertSame('140.00', $facture->totaux->taxTotal);
        self::assertSame('840.00', $facture->totaux->taxInclusive);
        self::assertSame('840.00', $facture->totaux->payable);
    }

    public function testReadsACreditNote(): void
    {
        $facture = (new LecteurUbl())->readFile(Fixtures::path('ubl/avoir.xml'));

        self::assertSame('A-2026-0003', $facture->number);
        self::assertSame('381', $facture->typeCode);
        self::assertTrue($facture->isCreditNote());
        self::assertSame('EUR', $facture->currency);
        self::assertCount(1, $facture->lines);
        self::assertSame('1', $facture->lines[0]->quantity);
        self::assertSame('Lampe de bureau', $facture->lines[0]->name);
        self::assertSame('60.00', $facture->totaux->taxInclusive);
    }

    public function testTheTradingNameIsUsedWhenTheRegistrationNameIsMissing(): void
    {
        $xml = str_replace('<cbc:RegistrationName>Mobilier Fictif SAS</cbc:RegistrationName>', '', Fixtures::read('ubl/complete.xml'));

        self::assertSame('Mobilier Fictif', (new LecteurUbl())->read($xml)->seller->name);
    }

    public function testTheTaxTotalWithoutBreakdownIsUsedAsAFallback(): void
    {
        $xml = (string) preg_replace('#<cac:TaxSubtotal>.*</cac:TaxSubtotal>#s', '', Fixtures::read('ubl/complete.xml'));
        $facture = (new LecteurUbl())->read($xml);

        self::assertSame('140.00', $facture->totaux->taxTotal);
        self::assertSame([], $facture->vatBreakdowns);
    }

    public function testAnInvoiceWithoutPartiesIsReadWithEmptyParties(): void
    {
        $xml = (string) preg_replace('#<cac:Accounting(Supplier|Customer)Party>.*</cac:Accounting\1Party>#s', '', Fixtures::read('ubl/complete.xml'));
        $facture = (new LecteurUbl())->read($xml);

        self::assertNull($facture->seller->name);
        self::assertNull($facture->buyer->address);
        self::assertNull($facture->buyer->siren());
    }

    public function testACiiDocumentIsRejected(): void
    {
        $this->expectException(InvalidDocument::class);
        $this->expectExceptionMessage('Le document n\'est pas une facture UBL (Invoice ou CreditNote) (élément racine trouvé : « CrossIndustryInvoice »).');

        (new LecteurUbl())->read(Fixtures::read('cii/complete.xml'));
    }

    public function testAnInvalidDateIsRejected(): void
    {
        $this->expectException(InvalidDocument::class);
        $this->expectExceptionMessage('La date « 2026-02-30 » (BT-2) n\'est pas une date valide.');

        (new LecteurUbl())->read(str_replace('2026-10-20', '2026-02-30', Fixtures::read('ubl/complete.xml')));
    }

    public function testAMissingFileIsReported(): void
    {
        $this->expectException(InvalidDocument::class);

        (new LecteurUbl())->readFile(Fixtures::path('ubl/absent.xml'));
    }
}
