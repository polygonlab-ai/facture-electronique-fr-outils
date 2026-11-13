<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PolygonLab\FactureElectronique\Adresse;
use PolygonLab\FactureElectronique\CategorieOperation;
use PolygonLab\FactureElectronique\Facture;
use PolygonLab\FactureElectronique\FacturXProfile;
use PolygonLab\FactureElectronique\Format;
use PolygonLab\FactureElectronique\Partie;
use PolygonLab\FactureElectronique\Rule;

final class ModeleTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?CategorieOperation}>
     */
    public static function cadres(): iterable
    {
        yield 'B1' => ['B1', CategorieOperation::LB];
        yield 'S2' => ['S2', CategorieOperation::PS];
        yield 'm4 en minuscule' => [' m4 ', CategorieOperation::LBPS];
        yield 'code inconnu' => ['A1', null];
        yield 'texte libre' => ['urn:fdc:peppol.eu:2017:poacc:billing:01:1.0', null];
        yield 'absent' => [null, null];
    }

    #[DataProvider('cadres')]
    public function testTheCategoryIsDeducedFromTheBillingFramework(?string $code, ?CategorieOperation $expected): void
    {
        self::assertSame($expected, CategorieOperation::fromCadreDeFacturation($code));
    }

    public function testCategoryLabels(): void
    {
        self::assertSame('livraison de biens', CategorieOperation::LB->label());
        self::assertSame('prestation de services', CategorieOperation::PS->label());
        self::assertSame('livraison de biens et prestation de services', CategorieOperation::LBPS->label());
        self::assertTrue(CategorieOperation::LBPS->includesGoods());
        self::assertFalse(CategorieOperation::PS->includesGoods());
    }

    public function testAddress(): void
    {
        $address = new Adresse('1 rue de l\'Exemple', 'Bâtiment A', 'Lyon', '69000', 'FR');

        self::assertTrue($address->isComplete());
        self::assertFalse($address->isEmpty());
        self::assertSame('1 rue de l\'Exemple, Bâtiment A, 69000 Lyon, FR', $address->format());
        self::assertTrue((new Adresse())->isEmpty());
        self::assertSame('', (new Adresse())->format());
        self::assertFalse((new Adresse(countryCode: 'FR'))->isComplete());
        self::assertFalse($address->sameAs(new Adresse('1 rue de l\'Exemple', null, 'Lyon', '69000', 'FR')));
    }

    public function testPartyIdentifiers(): void
    {
        self::assertSame('444444442', (new Partie(legalId: '444 444 442'))->siren()?->toString());
        self::assertSame('777777772', (new Partie(legalId: '77777777200016', legalIdScheme: '0009'))->siren()?->toString());
        self::assertNull((new Partie(legalId: 'DE123', legalIdScheme: '0088'))->siren());
        self::assertNull((new Partie())->siren());
        self::assertFalse((new Partie())->hasInvalidSiren());
        self::assertTrue((new Partie(legalId: '444444443', legalIdScheme: '0002'))->hasInvalidSiren());
        self::assertFalse((new Partie(legalId: '444444443'))->hasInvalidSiren());
        self::assertNull((new Partie(vatNumber: 'DE123456789'))->numeroTvaFrancais());
        self::assertNull((new Partie())->numeroTvaFrancais());
    }

    public function testTheCategoryCanBeGivenExplicitly(): void
    {
        $facture = new Facture(Format::Ubl, businessProcess: 'urn:exemple:processus');
        self::assertNull($facture->categorieOperation);

        $copy = $facture->withCategorieOperation(CategorieOperation::PS);
        self::assertSame(CategorieOperation::PS, $copy->categorieOperation);
        self::assertNull($facture->categorieOperation);
        self::assertSame('urn:exemple:processus', $copy->businessProcess);
    }

    /**
     * @return iterable<string, array{Format, ?string, list<string>, bool}>
     */
    public static function debitMentions(): iterable
    {
        yield 'code CII 5' => [Format::Cii, '5', [], true];
        yield 'code UBL 3' => [Format::Ubl, '3', [], true];
        yield 'code UBL 5' => [Format::Ubl, '5', [], false];
        yield 'note typographique' => [Format::Ubl, null, ['TVA : OPTION POUR LE PAIEMENT DE LA TAXE D’APRÈS LES   DÉBITS'], true];
        yield 'autre note' => [Format::Cii, '72', ['Paiement à 30 jours.'], false];
    }

    /**
     * @param list<string> $notes
     */
    #[DataProvider('debitMentions')]
    public function testTheDebitOptionMention(Format $format, ?string $code, array $notes, bool $expected): void
    {
        self::assertSame($expected, (new Facture($format, vatPointDateCode: $code, notes: $notes))->hasOptionDebitsMention());
    }

    public function testProfiles(): void
    {
        self::assertNull(FacturXProfile::fromGuidelineId(null));
        self::assertSame(FacturXProfile::BasicWl, FacturXProfile::fromGuidelineId(' urn:factur-x.eu:1p0:basicwl '));
        self::assertFalse(FacturXProfile::Minimum->hasLines());
        self::assertFalse(FacturXProfile::BasicWl->hasLines());
        self::assertTrue(FacturXProfile::Extended->hasLines());
    }

    public function testEachRuleHasItsApplicationDate(): void
    {
        $in2027 = array_values(array_filter(
            Rule::cases(),
            static fn(Rule $rule): bool => $rule->applicableFrom()->format('Y-m-d') === Rule::DATE_2027,
        ));

        self::assertSame(
            [Rule::AdresseLivraisonIncomplete, Rule::LignesAbsentes, Rule::DesignationAbsente, Rule::QuantiteAbsente, Rule::PrixUnitaireAbsent],
            $in2027,
        );
        self::assertSame(Rule::DATE_2026, Rule::SirenClientAbsent->applicableFrom()->format('Y-m-d'));
        self::assertSame(Rule::DATE_2026, Rule::CategorieOperationAbsente->applicableFrom()->format('Y-m-d'));
        self::assertSame(Rule::DATE_2026, Rule::MentionOptionDebitsAbsente->applicableFrom()->format('Y-m-d'));
        self::assertSame(Rule::DATE_2026, Rule::NumeroAbsent->applicableFrom()->format('Y-m-d'));
    }

    public function testEveryRuleIsDocumented(): void
    {
        $reform = 0;
        foreach (Rule::cases() as $rule) {
            self::assertMatchesRegularExpression('/\A[A-Z_]+\z/', $rule->value);
            self::assertNotSame('', $rule->label());
            self::assertMatchesRegularExpression('/\AB[GT]-\d+/', $rule->terms());
            self::assertNotSame('', $rule->source());
            $reform += $rule->isReformMention() ? 1 : 0;
        }
        self::assertSame(5, $reform);
        self::assertSame('CGI, annexe II, art. 242 nonies A (décret n° 2022-1299 du 7 octobre 2022)', Rule::CategorieOperationAbsente->source());
        self::assertSame('CGI, annexe II, art. 242 nonies A ; code de commerce, art. R.123-221', Rule::SirenClientAbsent->source());
        self::assertSame('Norme EN 16931', Rule::DeviseAbsente->source());
        self::assertSame('CGI, annexe II, art. 242 nonies A', Rule::NumeroAbsent->source());
    }
}
