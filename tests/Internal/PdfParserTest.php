<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Tests\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PolygonLab\FactureElectronique\Internal\Pdf\Parser;
use PolygonLab\FactureElectronique\Internal\Pdf\PdfArray;
use PolygonLab\FactureElectronique\Internal\Pdf\PdfDictionary;
use PolygonLab\FactureElectronique\Internal\Pdf\PdfDocument;
use PolygonLab\FactureElectronique\Internal\Pdf\PdfKeyword;
use PolygonLab\FactureElectronique\Internal\Pdf\PdfNumber;
use PolygonLab\FactureElectronique\Internal\Pdf\PdfReference;
use PolygonLab\FactureElectronique\Internal\Pdf\PdfStream;
use PolygonLab\FactureElectronique\Internal\Pdf\PdfString;
use PolygonLab\FactureElectronique\Internal\Pdf\PdfValue;
use PolygonLab\FactureElectronique\Internal\Pdf\StreamDecoder;
use PolygonLab\FactureElectronique\Internal\Pdf\SyntaxError;
use PolygonLab\FactureElectronique\Tests\Support\PdfBuilder;

final class PdfParserTest extends TestCase
{
    public function testParsesADictionaryWithEveryKindOfValue(): void
    {
        $value = (new Parser("<< /Type /Filespec /Size 12 /Ratio -1.5 /Half .5 /Ref 7 0 R /List [1 (a) <41 42>] /Ok true /Ko false /Rien null % commentaire\n /Nom#20Espace 3 >>"))->parseValue();

        self::assertInstanceOf(PdfDictionary::class, $value);
        self::assertTrue($value->isName('Type', 'Filespec'));
        self::assertSame(12, $value->integer('Size'));
        self::assertEqualsWithDelta(-1.5, self::number($value->get('Ratio')), 0.0001);
        self::assertEqualsWithDelta(0.5, self::number($value->get('Half')), 0.0001);
        self::assertNull($value->integer('Ratio'));
        $reference = $value->get('Ref');
        self::assertInstanceOf(PdfReference::class, $reference);
        self::assertSame('7 0 R', $reference->toString());
        $list = $value->get('List');
        self::assertInstanceOf(PdfArray::class, $list);
        self::assertCount(3, $list->items);
        $hex = $list->items[2];
        self::assertInstanceOf(PdfString::class, $hex);
        self::assertSame('AB', $hex->bytes);
        self::assertSame(PdfKeyword::True, $value->get('Ok'));
        self::assertSame(PdfKeyword::False, $value->get('Ko'));
        self::assertSame(PdfKeyword::Null, $value->get('Rien'));
        self::assertSame(3, $value->integer('Nom Espace'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function literalStrings(): iterable
    {
        yield 'échappements' => ['(a\\nb\\rc\\td\\be\\ff\\(g\\)h\\\\i)', "a\nb\rc\td\x08e\x0Cf(g)h\\i"];
        yield 'parenthèses imbriquées' => ['(a (b) c)', 'a (b) c'];
        yield 'octal' => ['(\\101\\60\\7x)', "A0\x07x"];
        yield 'suite de ligne CR LF' => ["(ab\\\r\ncd)", 'abcd'];
        yield 'suite de ligne CR' => ["(ab\\\rcd)", 'abcd'];
        yield 'suite de ligne LF' => ["(ab\\\ncd)", 'abcd'];
        yield 'lettre échappée inconnue' => ['(\\q)', 'q'];
        yield 'hexadécimal impair et blancs' => ['<4 1 4>', 'A@'];
    }

    #[DataProvider('literalStrings')]
    public function testParsesStrings(string $source, string $expected): void
    {
        $value = (new Parser($source))->parseValue();

        self::assertInstanceOf(PdfString::class, $value);
        self::assertSame($expected, $value->bytes);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function syntaxErrors(): iterable
    {
        yield 'vide' => ['   '];
        yield 'dictionnaire non terminé' => ['<< /A 1'];
        yield 'clé invalide' => ['<< 1 2 >>'];
        yield 'tableau non terminé' => ['[1 2'];
        yield 'hexadécimal non terminé' => ['<414'];
        yield 'hexadécimal invalide' => ['<4G>'];
        yield 'chaîne non terminée' => ['(abc'];
        yield 'mot-clé inconnu' => ['endobj'];
        yield 'nombre invalide' => ['-x'];
        yield 'imbrication trop profonde' => [str_repeat('[', 70) . str_repeat(']', 70)];
    }

    #[DataProvider('syntaxErrors')]
    public function testRejectsInvalidSyntax(string $source): void
    {
        $this->expectException(SyntaxError::class);

        (new Parser($source))->parseValue();
    }

    public function testANumberFollowedByAnotherNumberIsNotAReference(): void
    {
        $parser = new Parser('12 0 13');

        $first = $parser->parseValue();
        self::assertInstanceOf(PdfNumber::class, $first);
        self::assertSame(12, $first->value);
        self::assertSame(0, self::number($parser->parseValue()));
        self::assertSame(13, self::number($parser->parseValue()));
    }

    public function testAVeryLargeIntegerBecomesAFloat(): void
    {
        self::assertIsFloat(self::number((new Parser('99999999999999999999999'))->parseValue()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function textStrings(): iterable
    {
        yield 'UTF-16BE' => ["\xFE\xFF\x00f\x00\xE9", 'fé'];
        yield 'UTF-16BE hors plan de base' => ["\xFE\xFF\xD8\x3D\xDE\x00", "\u{1F600}"];
        yield 'UTF-16BE indicateur isolé' => ["\xFE\xFF\xD8\x3D\x00a", "\u{FFFD}a"];
        yield 'UTF-16BE trois octets' => ["\xFE\xFF\x20\xAC", '€'];
        yield 'UTF-8' => ["\xEF\xBB\xBFfé", 'fé'];
        yield 'Latin-1' => ["caf\xE9", 'café'];
    }

    #[DataProvider('textStrings')]
    public function testConvertsTextStringsToUtf8(string $bytes, string $expected): void
    {
        self::assertSame($expected, (new PdfString($bytes))->text());
    }

    public function testAnObjectStreamWithAnInvalidHeaderIsIgnored(): void
    {
        $content = 'x y << /A 1 >>';
        $data = (string) gzcompress($content);
        $pdf = "%PDF-1.7\n5 0 obj\n" . PdfBuilder::stream('/Type /ObjStm /N 1 /First 4 /Filter /FlateDecode', $data) . "\nendobj\n";

        $document = PdfDocument::parse($pdf, new StreamDecoder(1000));

        self::assertNotNull($document->get(5));
        self::assertNull($document->get(1));
    }

    public function testAnObjectStreamWithoutCountIsIgnored(): void
    {
        $pdf = "%PDF-1.7\n5 0 obj\n" . PdfBuilder::stream('/Type /ObjStm', '1 0 << >>') . "\nendobj\n";

        self::assertNull(PdfDocument::parse($pdf, new StreamDecoder(1000))->get(1));
    }

    public function testADirectObjectWinsOverTheSameObjectInAnObjectStream(): void
    {
        $content = '1 0 << /Origine (flux) >>';
        $pdf = "%PDF-1.7\n1 0 obj\n<< /Origine (direct) >>\nendobj\n5 0 obj\n"
            . PdfBuilder::stream('/Type /ObjStm /N 1 /First 4 /Filter /FlateDecode', (string) gzcompress($content)) . "\nendobj\n";

        $object = PdfDocument::parse($pdf, new StreamDecoder(1000))->get(1);

        self::assertInstanceOf(PdfDictionary::class, $object);
        $origin = $object->get('Origine');
        self::assertInstanceOf(PdfString::class, $origin);
        self::assertSame('direct', $origin->bytes);
    }

    public function testAStreamWithAWrongLengthIsReadUpToEndstream(): void
    {
        $pdf = "%PDF-1.7\n1 0 obj\n<< /Length 999 >>\nstream\r\nabc\r\nendstream\nendobj\n2 0 obj\n<< /Length 3 >>\nstream\rxyz\rendstream\nendobj\n3 0 obj\n<< /Length 1 >>\nstream\nabcdef\nendobj\n";
        $document = PdfDocument::parse($pdf, new StreamDecoder(1000));

        $first = $document->get(1);
        self::assertInstanceOf(PdfStream::class, $first);
        self::assertSame('abc', $first->raw);
        $second = $document->get(2);
        self::assertInstanceOf(PdfStream::class, $second);
        self::assertSame('xyz', $second->raw);
        // Flux sans « endstream » : objet ignoré.
        self::assertNull($document->get(3));
    }

    public function testReferencesAreFollowedButLoopsAreBroken(): void
    {
        $pdf = "%PDF-1.7\n1 0 obj\n2 0 R\nendobj\n2 0 obj\n1 0 R\nendobj\n3 0 obj\n4 0 R\nendobj\n4 0 obj\n(fin)\nendobj\ntrailer\n<< /Size 5 >>\ntrailer\n<< /Size\n";
        $document = PdfDocument::parse($pdf, new StreamDecoder(1000));

        self::assertNull($document->resolve(new PdfReference(1, 0)));
        self::assertInstanceOf(PdfString::class, $document->resolve(new PdfReference(3, 0)));
        self::assertFalse($document->isEncrypted());
        self::assertNull($document->resolve(null));
    }

    public function testDeeplyNestedValuesAreNotExploredForever(): void
    {
        $nested = str_repeat('[', 40) . '<< /EF 1 >>' . str_repeat(']', 40);
        $pdf = "%PDF-1.7\n1 0 obj\n" . $nested . "\nendobj\n";

        self::assertSame([], PdfDocument::parse($pdf, new StreamDecoder(1000))->dictionaries());
    }

    public function testKeywordsAreRecognizedOnlyAsWholeWords(): void
    {
        self::assertFalse((new Parser('streamX'))->nextIsKeyword('stream'));
        self::assertTrue((new Parser('  stream'))->nextIsKeyword('stream'));
    }

    public function testSkipStopsAtTheEndOfTheData(): void
    {
        $parser = new Parser('abc');
        $parser->skip(10);

        self::assertSame(3, $parser->position());
    }

    private static function number(?PdfValue $value): int|float
    {
        self::assertInstanceOf(PdfNumber::class, $value);

        return $value->value;
    }
}
