<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PolygonLab\FactureElectronique\Adresse;
use PolygonLab\FactureElectronique\CategorieOperation;
use PolygonLab\FactureElectronique\ControleMentions;
use PolygonLab\FactureElectronique\Facture;
use PolygonLab\FactureElectronique\Format;
use PolygonLab\FactureElectronique\LecteurCii;
use PolygonLab\FactureElectronique\LecteurUbl;
use PolygonLab\FactureElectronique\Ligne;
use PolygonLab\FactureElectronique\Partie;
use PolygonLab\FactureElectronique\Rule;
use PolygonLab\FactureElectronique\Tests\Support\Fixtures;
use PolygonLab\FactureElectronique\Totaux;
use PolygonLab\FactureElectronique\VentilationTva;

final class ControleMentionsTest extends TestCase
{
    /** Date d'application de la plupart des données. */
    private const DATE_2026 = '2026-09-01';

    /** Date d'application des données des lignes et de l'adresse de livraison. */
    private const DATE_2027 = '2027-09-01';

    public function testCompleteInvoicesAreCompliantAtEveryDate(): void
    {
        $control = new ControleMentions(optionDebits: true);
        foreach (['2026-08-31', self::DATE_2026, self::DATE_2027] as $date) {
            $cii = $control->check((new LecteurCii())->readFile(Fixtures::path('cii/complete.xml')), new \DateTimeImmutable($date));
            $ubl = $control->check((new LecteurUbl())->readFile(Fixtures::path('ubl/complete.xml')), new \DateTimeImmutable($date));
            $avoir = $control->check((new LecteurUbl())->readFile(Fixtures::path('ubl/avoir.xml')), new \DateTimeImmutable($date));

            self::assertTrue($cii->isCompliant(), implode(', ', $cii->codes()));
            self::assertTrue($ubl->isCompliant(), implode(', ', $ubl->codes()));
            self::assertTrue($avoir->isCompliant(), implode(', ', $avoir->codes()));
        }
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function incompleteInvoices(): iterable
    {
        yield 'SIREN du client absent' => ['cii/sans-siren-client.xml', ['SIREN_CLIENT_ABSENT'], self::DATE_2026];
        yield 'catégorie de l\'opération absente' => ['cii/sans-categorie-operation.xml', ['CATEGORIE_OPERATION_ABSENTE'], self::DATE_2026];
        yield 'option sur les débits absente' => ['cii/sans-option-debits.xml', ['MENTION_OPTION_DEBITS_ABSENTE'], self::DATE_2026];
        // Catégorie inconnue : rien n'exclut une prestation de services, la mention des débits reste exigée.
        yield 'SIREN invalide et catégorie absente (UBL)' => [
            'ubl/siren-invalide-sans-categorie.xml',
            ['SIREN_CLIENT_INVALIDE', 'CATEGORIE_OPERATION_ABSENTE', 'MENTION_OPTION_DEBITS_ABSENTE'],
            self::DATE_2026,
        ];
        yield 'adresse de livraison incomplète' => ['cii/adresse-livraison-incomplete.xml', ['ADRESSE_LIVRAISON_INCOMPLETE'], self::DATE_2027];
    }

    /**
     * @param list<string> $codes
     */
    #[DataProvider('incompleteInvoices')]
    public function testAMentionIsReportedOnlyFromItsApplicationDate(string $fixture, array $codes, string $date): void
    {
        $facture = str_starts_with($fixture, 'ubl/')
            ? (new LecteurUbl())->readFile(Fixtures::path($fixture))
            : (new LecteurCii())->readFile(Fixtures::path($fixture));
        $control = new ControleMentions(optionDebits: true);
        $day = new \DateTimeImmutable($date);

        $before = $control->check($facture, $day->modify('-1 day'));
        $after = $control->check($facture, $day);

        self::assertTrue($before->isCompliant(), implode(', ', $before->codes()));
        self::assertSame($codes, $after->codes());
        foreach ($after->violations as $violation) {
            self::assertSame($date, $violation->applicableFrom->format('Y-m-d'));
            self::assertTrue($violation->rule->isReformMention());
        }
    }

    public function testARuleDatedSeptember2027IsNotReportedBeforeThatDate(): void
    {
        // Facture du démarrage rapide : SIREN du client absent (2026), adresse de livraison incomplète (2027).
        $facture = (new LecteurCii())->readFile(Fixtures::path('cii/exemple-demarrage-rapide.xml'));
        $control = new ControleMentions();

        self::assertTrue($control->check($facture, new \DateTimeImmutable('2026-08-31'))->isCompliant());
        self::assertSame(['SIREN_CLIENT_ABSENT'], $control->check($facture, new \DateTimeImmutable(self::DATE_2026))->codes());
        self::assertSame(['SIREN_CLIENT_ABSENT'], $control->check($facture, new \DateTimeImmutable('2027-08-31'))->codes());
        self::assertSame(
            ['SIREN_CLIENT_ABSENT', 'ADRESSE_LIVRAISON_INCOMPLETE'],
            $control->check($facture, new \DateTimeImmutable(self::DATE_2027))->codes(),
        );
    }

    public function testTheReferenceDateIsTakenAsACalendarDay(): void
    {
        $facture = (new LecteurCii())->readFile(Fixtures::path('cii/adresse-livraison-incomplete.xml'));

        // 1er septembre 2027 à 0 h 30, heure de Paris : déjà le jour d'application.
        $report = (new ControleMentions())->check($facture, new \DateTimeImmutable('2027-09-01 00:30', new \DateTimeZone('Europe/Paris')));

        self::assertSame(['ADRESSE_LIVRAISON_INCOMPLETE'], $report->codes());
        self::assertSame(self::DATE_2027, $report->referenceDate->format('Y-m-d'));
    }

    public function testLineDataIsExpectedFromSeptember2027AndTheRestFromSeptember2026(): void
    {
        $facture = (new LecteurCii())->readFile(Fixtures::path('cii/mentions-classiques-absentes.xml'));
        $control = new ControleMentions();

        self::assertTrue($control->check($facture, new \DateTimeImmutable('2026-08-31'))->isCompliant());

        $in2026 = $control->check($facture, new \DateTimeImmutable(self::DATE_2026));
        self::assertSame([
            'NUMERO_ABSENT',
            'DATE_ABSENTE',
            'DEVISE_ABSENTE',
            'NOM_VENDEUR_ABSENT',
            'ADRESSE_VENDEUR_INCOMPLETE',
            'TVA_VENDEUR_ABSENTE',
            'NOM_CLIENT_ABSENT',
            'ADRESSE_CLIENT_INCOMPLETE',
            'TAUX_TVA_ABSENT',
            'BASE_TVA_ABSENTE',
            'MONTANT_TVA_ABSENT',
            'TOTAL_HT_ABSENT',
            'TOTAL_TTC_ABSENT',
        ], $in2026->codes());
        foreach ($in2026->violations as $violation) {
            self::assertSame(self::DATE_2026, $violation->applicableFrom->format('Y-m-d'));
        }

        $in2027 = $control->check($facture, new \DateTimeImmutable(self::DATE_2027));
        self::assertSame(['DESIGNATION_ABSENTE', 'QUANTITE_ABSENTE', 'PRIX_UNITAIRE_ABSENT'], array_values(array_diff($in2027->codes(), $in2026->codes())));

        $lines = array_values(array_filter($in2027->violations, static fn($violation): bool => $violation->rule === Rule::DesignationAbsente));
        self::assertCount(1, $lines);
        self::assertSame('A', $lines[0]->lineId);
        self::assertSame('Ligne A : la désignation (BT-153) est absente.', $lines[0]->message);
        self::assertSame(self::DATE_2027, $lines[0]->applicableFrom->format('Y-m-d'));
        self::assertTrue($in2027->has(Rule::TotalTtcAbsent));
        self::assertFalse($in2027->has(Rule::LignesAbsentes));
    }

    public function testAnInvoiceWithoutLinesOrVatBreakdownIsReported(): void
    {
        $facture = (new LecteurCii())->readFile(Fixtures::path('cii/minimum.xml'));
        $control = new ControleMentions();

        self::assertSame(
            ['CATEGORIE_OPERATION_ABSENTE', 'ADRESSE_VENDEUR_INCOMPLETE', 'ADRESSE_CLIENT_INCOMPLETE', 'VENTILATION_TVA_ABSENTE'],
            $control->check($facture, new \DateTimeImmutable('2026-12-01'))->codes(),
        );
        self::assertSame(
            ['CATEGORIE_OPERATION_ABSENTE', 'ADRESSE_VENDEUR_INCOMPLETE', 'ADRESSE_CLIENT_INCOMPLETE', 'LIGNES_ABSENTES', 'VENTILATION_TVA_ABSENTE'],
            $control->check($facture, new \DateTimeImmutable(self::DATE_2027))->codes(),
        );
    }

    public function testAForeignBuyerNeedsNoSirenButAVatNumberForAnIntraCommunitySupply(): void
    {
        $facture = self::invoice(
            buyer: new Partie('Kunde Fiktiv GmbH', new Adresse('Beispielstraße 1', null, 'Berlin', '10115', 'DE')),
            vatCategory: 'K',
        );
        $report = (new ControleMentions())->check($facture, new \DateTimeImmutable(self::DATE_2027));

        self::assertSame(['TVA_CLIENT_ABSENTE'], $report->codes());
    }

    public function testAnInvalidFrenchSellerVatNumberIsReported(): void
    {
        $facture = self::invoice(seller: self::seller('FR00333333334'));
        $report = (new ControleMentions())->check($facture, new \DateTimeImmutable(self::DATE_2027));

        self::assertSame(['TVA_VENDEUR_INVALIDE'], $report->codes());
        self::assertSame('Le numéro de TVA du vendeur (BT-31) « FR00333333334 » n\'est pas un numéro français valide.', $report->violations[0]->message);
    }

    public function testAForeignSellerVatNumberIsNotChecked(): void
    {
        $facture = self::invoice(seller: self::seller('DE123456789'));

        self::assertTrue((new ControleMentions())->check($facture, new \DateTimeImmutable(self::DATE_2027))->isCompliant());
    }

    public function testAnExemptInvoiceNeedsNoSellerVatNumber(): void
    {
        $facture = self::invoice(seller: self::seller(null), vatCategory: 'E');

        self::assertTrue((new ControleMentions())->check($facture, new \DateTimeImmutable(self::DATE_2027))->isCompliant());
    }

    public function testVatCategoriesOfLinesAreUsedWithoutBreakdown(): void
    {
        $facture = self::invoice(seller: self::seller(null), vatCategory: 'O', withBreakdown: false);
        $report = (new ControleMentions())->check($facture, new \DateTimeImmutable(self::DATE_2027));

        // Hors champ (O) : ni taux ni numéro de TVA du vendeur exigés, mais la ventilation reste attendue.
        self::assertSame(['VENTILATION_TVA_ABSENTE'], $report->codes());
    }

    public function testTheDebitOptionIsNotRequiredForASupplyOfGoods(): void
    {
        $facture = self::invoice(categorie: CategorieOperation::LB);
        $report = (new ControleMentions(optionDebits: true))->check($facture, new \DateTimeImmutable(self::DATE_2027));

        self::assertTrue($report->isCompliant());
    }

    public function testTheDebitOptionIsCheckedOnlyWhenTheSellerHasOpted(): void
    {
        $facture = self::invoice(categorie: CategorieOperation::PS);

        self::assertTrue((new ControleMentions())->check($facture, new \DateTimeImmutable(self::DATE_2027))->isCompliant());
        self::assertSame(
            ['MENTION_OPTION_DEBITS_ABSENTE'],
            (new ControleMentions(optionDebits: true))->check($facture, new \DateTimeImmutable(self::DATE_2027))->codes(),
        );
    }

    public function testADeliveryAddressIdenticalToTheBuyerAddressIsAccepted(): void
    {
        $facture = self::invoice(delivery: new Adresse('2 avenue du Test', null, 'Bordeaux', '33000', 'FR'));

        self::assertTrue((new ControleMentions())->check($facture, new \DateTimeImmutable(self::DATE_2027))->isCompliant());
        self::assertTrue($facture->deliveryAddress?->sameAs(new Adresse('2  AVENUE du test', null, 'bordeaux', '33000', 'fr')));
    }

    public function testADeliveryAddressIdenticalToTheBuyerAddressIsNotRequired(): void
    {
        $incomplete = new Adresse('2 avenue du Test', null, null, null, 'FR');
        $facture = self::invoice(
            buyer: new Partie('Client Fictif SARL', $incomplete, '444444442', '0002', 'FR50444444442'),
            delivery: new Adresse('2 avenue du test', null, null, null, 'FR'),
        );

        // L'adresse du client est incomplète, mais l'adresse de livraison, identique, n'est pas exigée.
        self::assertSame(['ADRESSE_CLIENT_INCOMPLETE'], (new ControleMentions())->check($facture, new \DateTimeImmutable(self::DATE_2027))->codes());
    }

    private static function seller(?string $vatNumber): Partie
    {
        return new Partie('Atelier Fictif SAS', new Adresse('1 rue de l\'Exemple', null, 'Lyon', '69000', 'FR'), '333333334', '0002', $vatNumber);
    }

    private static function invoice(
        ?Partie $seller = null,
        ?Partie $buyer = null,
        string $vatCategory = 'S',
        bool $withBreakdown = true,
        CategorieOperation $categorie = CategorieOperation::LB,
        ?Adresse $delivery = null,
    ): Facture {
        $rate = $vatCategory === 'O' ? null : ($vatCategory === 'S' ? '20' : '0');

        return new Facture(
            format: Format::Cii,
            number: 'F-1',
            issueDate: new \DateTimeImmutable('2027-10-01'),
            typeCode: '380',
            currency: 'EUR',
            seller: $seller ?? self::seller('FR48333333334'),
            buyer: $buyer ?? new Partie('Client Fictif SARL', new Adresse('2 avenue du Test', null, 'Bordeaux', '33000', 'FR'), '444444442', '0002', 'FR50444444442'),
            deliveryAddress: $delivery,
            lines: [new Ligne('1', 'Article', '1', 'C62', '100', '100', $vatCategory, $rate)],
            vatBreakdowns: $withBreakdown ? [new VentilationTva($vatCategory, $rate, '100', $rate === '20' ? '20' : '0')] : [],
            totaux: new Totaux('100', '100', $rate === '20' ? '20' : '0', '120', '120'),
            categorieOperation: $categorie,
        );
    }
}
