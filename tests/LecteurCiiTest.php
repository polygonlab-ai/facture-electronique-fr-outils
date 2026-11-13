<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PolygonLab\FactureElectronique\CategorieOperation;
use PolygonLab\FactureElectronique\FactureElectroniqueException;
use PolygonLab\FactureElectronique\Format;
use PolygonLab\FactureElectronique\InvalidDocument;
use PolygonLab\FactureElectronique\LecteurCii;
use PolygonLab\FactureElectronique\Tests\Support\Fixtures;

final class LecteurCiiTest extends TestCase
{
    public function testReadsTheCompleteInvoice(): void
    {
        $facture = (new LecteurCii())->readFile(Fixtures::path('cii/complete.xml'));

        self::assertSame(Format::Cii, $facture->format);
        self::assertSame('F-2026-0042', $facture->number);
        self::assertSame('2026-10-15', $facture->issueDate?->format('Y-m-d'));
        self::assertSame('380', $facture->typeCode);
        self::assertFalse($facture->isCreditNote());
        self::assertSame('EUR', $facture->currency);
        self::assertSame('5', $facture->vatPointDateCode);
        self::assertSame(['Option pour le paiement de la taxe d\'après les débits.'], $facture->notes);
        self::assertSame('S1', $facture->businessProcess);
        self::assertSame(CategorieOperation::PS, $facture->categorieOperation);
        self::assertSame('urn:cen.eu:en16931:2017', $facture->specificationId);
        self::assertTrue($facture->hasOptionDebitsMention());

        self::assertSame('Atelier Fictif SAS', $facture->seller->name);
        self::assertSame('333333334', $facture->seller->siren()?->toString());
        self::assertSame('FR48333333334', $facture->seller->numeroTvaFrancais()?->toString());
        self::assertSame('1 rue de l\'Exemple, 69000 Lyon, FR', $facture->seller->address?->format());
        self::assertSame('Client Fictif SARL', $facture->buyer->name);
        self::assertSame('444444442', $facture->buyer->siren()?->toString());
        self::assertSame('0002', $facture->buyer->legalIdScheme);
        self::assertSame('FR50444444442', $facture->buyer->vatNumber);
        self::assertNull($facture->deliveryAddress);
        self::assertNull($facture->deliveryDate);

        self::assertCount(2, $facture->lines);
        $line = $facture->lines[0];
        self::assertSame('1', $line->id);
        self::assertSame('Développement d\'un module de démonstration', $line->name);
        self::assertSame('10', $line->quantity);
        self::assertSame('HUR', $line->unitCode);
        self::assertSame('80.00', $line->netPrice);
        self::assertSame('800.00', $line->netAmount);
        self::assertSame('S', $line->vatCategory);
        self::assertSame('20.00', $line->vatRate);

        self::assertCount(1, $facture->vatBreakdowns);
        $vat = $facture->vatBreakdowns[0];
        self::assertSame('S', $vat->category);
        self::assertSame('20.00', $vat->rate);
        self::assertSame('1200.00', $vat->taxableAmount);
        self::assertSame('240.00', $vat->taxAmount);
        self::assertNull($vat->exemptionReason);

        self::assertSame('1200.00', $facture->totaux->lineTotal);
        self::assertSame('1200.00', $facture->totaux->taxExclusive);
        self::assertSame('240.00', $facture->totaux->taxTotal);
        self::assertSame('1440.00', $facture->totaux->taxInclusive);
        self::assertSame('1440.00', $facture->totaux->payable);
    }

    public function testReadsTheDeliveryAddressAndDate(): void
    {
        $facture = (new LecteurCii())->readFile(Fixtures::path('cii/adresse-livraison-incomplete.xml'));

        self::assertSame(CategorieOperation::LBPS, $facture->categorieOperation);
        self::assertSame('Zone d\'activité fictive', $facture->deliveryAddress?->line1);
        self::assertNull($facture->deliveryAddress->city);
        self::assertSame('FR', $facture->deliveryAddress->countryCode);
        self::assertSame('2026-10-12', $facture->deliveryDate?->format('Y-m-d'));
    }

    public function testReadsAMinimumProfileInvoiceWithoutLines(): void
    {
        $facture = (new LecteurCii())->readFile(Fixtures::path('cii/minimum.xml'));

        self::assertSame('urn:factur-x.eu:1p0:minimum', $facture->specificationId);
        self::assertSame([], $facture->lines);
        self::assertSame([], $facture->vatBreakdowns);
        self::assertNull($facture->categorieOperation);
        self::assertSame('600.00', $facture->totaux->taxInclusive);
    }

    public function testMissingTermsAreNull(): void
    {
        $facture = (new LecteurCii())->readFile(Fixtures::path('cii/mentions-classiques-absentes.xml'));

        self::assertNull($facture->number);
        self::assertNull($facture->issueDate);
        self::assertNull($facture->currency);
        self::assertNull($facture->seller->name);
        self::assertNull($facture->seller->vatNumber);
        self::assertNull($facture->buyer->address);
        self::assertSame('A', $facture->lines[0]->id);
        self::assertNull($facture->lines[0]->name);
        self::assertNull($facture->lines[0]->quantity);
        self::assertNull($facture->totaux->taxTotal);
    }

    public function testTheTaxTotalInTheInvoiceCurrencyIsChosen(): void
    {
        $xml = str_replace(
            '<ram:TaxTotalAmount currencyID="EUR">240.00</ram:TaxTotalAmount>',
            '<ram:TaxTotalAmount currencyID="USD">260.00</ram:TaxTotalAmount><ram:TaxTotalAmount currencyID="EUR">240.00</ram:TaxTotalAmount>',
            Fixtures::read('cii/complete.xml'),
        );

        self::assertSame('240.00', (new LecteurCii())->read($xml)->totaux->taxTotal);
    }

    public function testTheOnlyTaxTotalIsUsedWhenTheCurrencyIsUnknown(): void
    {
        $xml = str_replace('<ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>', '', Fixtures::read('cii/complete.xml'));

        self::assertSame('240.00', (new LecteurCii())->read($xml)->totaux->taxTotal);
    }

    public function testAmountsAreNormalized(): void
    {
        $xml = str_replace(
            ['<ram:ChargeAmount>80.00</ram:ChargeAmount>', '<ram:ChargeAmount>400.00</ram:ChargeAmount>'],
            ['<ram:ChargeAmount>+80.00</ram:ChargeAmount>', '<ram:ChargeAmount>.5</ram:ChargeAmount>'],
            Fixtures::read('cii/complete.xml'),
        );
        $facture = (new LecteurCii())->read($xml);

        self::assertSame('80.00', $facture->lines[0]->netPrice);
        self::assertSame('0.5', $facture->lines[1]->netPrice);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidDocuments(): iterable
    {
        yield 'vide' => ['   ', 'Le document XML est vide.'];
        yield 'mal formé' => ['<rsm:CrossIndustryInvoice', 'Le document XML est mal formé'];
        yield 'DOCTYPE' => [
            '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/hostname">]><x>&e;</x>',
            'déclaration DOCTYPE',
        ];
        yield 'UBL' => [Fixtures::read('ubl/complete.xml'), 'n\'est pas une facture CII (CrossIndustryInvoice) (élément racine trouvé : « Invoice »)'];
        yield 'montant' => [
            str_replace('<ram:GrandTotalAmount>1440.00</ram:GrandTotalAmount>', '<ram:GrandTotalAmount>1 440,00</ram:GrandTotalAmount>', Fixtures::read('cii/complete.xml')),
            'Le montant « 1 440,00 » (BT-112) n\'est pas un nombre décimal.',
        ];
        yield 'date' => [
            str_replace('20261015', '20261315', Fixtures::read('cii/complete.xml')),
            'La date « 20261315 » (BT-2) n\'est pas une date valide.',
        ];
        yield 'date au mauvais format' => [
            str_replace('20261015', '2026-10-15', Fixtures::read('cii/complete.xml')),
            'La date « 2026-10-15 » (BT-2)',
        ];
    }

    #[DataProvider('invalidDocuments')]
    public function testInvalidDocumentsAreRejectedWithAClearMessage(string $xml, string $message): void
    {
        try {
            (new LecteurCii())->read($xml);
            self::fail('Exception attendue.');
        } catch (InvalidDocument $e) {
            self::assertInstanceOf(FactureElectroniqueException::class, $e);
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testAMissingFileIsReported(): void
    {
        $this->expectException(InvalidDocument::class);
        $this->expectExceptionMessage('Le fichier « absent.xml » est introuvable ou illisible.');

        (new LecteurCii())->readFile('absent.xml');
    }
}
