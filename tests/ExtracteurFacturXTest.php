<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PolygonLab\FactureElectronique\ExtracteurFacturX;
use PolygonLab\FactureElectronique\FacturXProfile;
use PolygonLab\FactureElectronique\InvalidPdf;
use PolygonLab\FactureElectronique\Tests\Support\Fixtures;
use PolygonLab\FactureElectronique\Tests\Support\PdfBuilder;

final class ExtracteurFacturXTest extends TestCase
{
    public function testExtractsTheXmlOfAFacturXPdf(): void
    {
        $document = (new ExtracteurFacturX())->extractFromFile(Fixtures::path('pdf/facturx-en16931.pdf'));

        self::assertSame(Fixtures::read('cii/complete.xml'), $document->xml);
        self::assertSame('urn:cen.eu:en16931:2017', $document->guidelineId);
        self::assertSame(FacturXProfile::En16931, $document->profile);
        self::assertSame('F-2026-0042', $document->facture()->number);
    }

    public function testExtractsFromAnObjectStreamWithAnIndirectLength(): void
    {
        $document = (new ExtracteurFacturX())->extractFromFile(Fixtures::path('pdf/facturx-basic-flux-objets.pdf'));

        self::assertSame(FacturXProfile::Basic, $document->profile);
        self::assertSame('1440.00', $document->facture()->totaux->taxInclusive);
    }

    public function testAPdfWithoutAttachmentIsRejected(): void
    {
        $this->expectException(InvalidPdf::class);
        $this->expectExceptionMessage('Aucun fichier factur-x.xml n\'est joint à ce PDF.');

        (new ExtracteurFacturX())->extractFromFile(Fixtures::path('pdf/sans-piece-jointe.pdf'));
    }

    /**
     * @return iterable<string, array{FacturXProfile}>
     */
    public static function profiles(): iterable
    {
        foreach (FacturXProfile::cases() as $profile) {
            yield $profile->value => [$profile];
        }
    }

    #[DataProvider('profiles')]
    public function testDetectsEveryProfile(FacturXProfile $profile): void
    {
        $xml = str_replace('urn:cen.eu:en16931:2017', $profile->guidelineId(), Fixtures::read('cii/complete.xml'));

        $document = (new ExtracteurFacturX())->extract(self::pdf($xml));

        self::assertSame($profile, $document->profile);
        self::assertSame($profile->guidelineId(), $document->guidelineId);
    }

    public function testAnUnknownSpecificationGivesNoProfile(): void
    {
        $xml = str_replace('urn:cen.eu:en16931:2017', 'urn:exemple:autre-specification', Fixtures::read('cii/complete.xml'));

        $document = (new ExtracteurFacturX())->extract(self::pdf($xml));

        self::assertNull($document->profile);
        self::assertSame('urn:exemple:autre-specification', $document->guidelineId);
    }

    public function testTheAttachmentNameIsReadFromAnEscapedLiteralString(): void
    {
        $pdf = self::pdf(Fixtures::read('cii/minimum.xml'), fileSpecName: '(factur\055x\056xml)');

        self::assertSame(FacturXProfile::Minimum, (new ExtracteurFacturX())->extract($pdf)->profile);
    }

    public function testAnUncompressedAttachmentIsRead(): void
    {
        $xml = Fixtures::read('cii/minimum.xml');

        self::assertSame($xml, (new ExtracteurFacturX())->extract(self::pdf($xml, compress: false))->xml);
    }

    public function testAFilterArrayIsRead(): void
    {
        $xml = Fixtures::read('cii/minimum.xml');

        self::assertSame($xml, (new ExtracteurFacturX())->extract(self::pdf($xml, filter: '[/FlateDecode] /DecodeParms [null]'))->xml);
    }

    public function testTheLastVersionOfAnIncrementallyUpdatedFileWins(): void
    {
        $old = self::pdf(Fixtures::read('cii/minimum.xml'));
        $new = (string) gzcompress(Fixtures::read('cii/complete.xml'));
        // Mise à jour incrémentale : le flux joint (objet 2) est redéfini à la fin du fichier.
        $update = sprintf(
            "2 0 obj\n%s\nendobj\n",
            PdfBuilder::stream('/Type /EmbeddedFile /Filter /FlateDecode', $new),
        );

        $document = (new ExtracteurFacturX())->extract($old . $update);

        self::assertSame(FacturXProfile::En16931, $document->profile);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidPdfs(): iterable
    {
        $xml = Fixtures::read('cii/complete.xml');

        yield 'pas un PDF' => ['<html>Bonjour</html>', 'Le fichier n\'est pas un PDF (en-tête « %PDF- » absent).'];
        yield 'chiffré' => [self::pdf($xml, trailerExtra: ' /Encrypt << /Filter /Standard >>'), 'Le PDF est chiffré : ses fichiers joints ne peuvent pas être lus.'];
        yield 'autre fichier joint' => [self::pdf($xml, fileSpecName: '(facture.xml)'), 'Aucun fichier factur-x.xml n\'est joint à ce PDF.'];
        yield 'flux absent' => [self::pdf($xml, embeddedReference: '99 0 R'), 'son contenu (objet 99 0 R) est introuvable dans le PDF'];
        yield 'flux non référencé' => [self::pdf($xml, embeddedReference: '(texte)'), 'son contenu (objet non référencé) est introuvable'];
        yield 'filtre LZW' => [self::pdf($xml, filter: '/LZWDecode'), 'filtre non pris en charge (/LZWDecode)'];
        yield 'filtre illisible' => [self::pdf($xml, filter: '[(Flate)]'), 'filtre non pris en charge (filtre illisible)'];
        yield 'prédicteur' => [self::pdf($xml, filter: '/FlateDecode /DecodeParms << /Predictor 12 >>'), 'prédicteur non pris en charge (12)'];
        yield 'données corrompues' => [self::pdf($xml, corrupt: true), 'Un flux compressé du PDF est illisible'];
        yield 'XML UBL joint' => [self::pdf(Fixtures::read('ubl/complete.xml')), 'l\'élément racine est « Invoice » au lieu de CrossIndustryInvoice.'];
        yield 'XML mal formé joint' => [self::pdf('<rsm:CrossIndustryInvoice'), 'n\'est pas une facture CII lisible : Le document XML est mal formé'];
    }

    #[DataProvider('invalidPdfs')]
    public function testInvalidPdfsAreRejectedWithAClearMessage(string $pdf, string $message): void
    {
        try {
            (new ExtracteurFacturX())->extract($pdf);
            self::fail('Exception attendue.');
        } catch (InvalidPdf $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testADecompressionBombIsStopped(): void
    {
        $xml = Fixtures::read('cii/complete.xml') . '<!--' . str_repeat(' ', 2_000_000) . '-->';

        $this->expectException(InvalidPdf::class);
        $this->expectExceptionMessage('Un flux décompressé du PDF dépasse la taille autorisée (100000 octets).');

        (new ExtracteurFacturX(100_000))->extract(self::pdf($xml));
    }

    public function testAnUncompressedStreamAboveTheLimitIsStopped(): void
    {
        $this->expectException(InvalidPdf::class);
        $this->expectExceptionMessage('dépasse la taille autorisée (1000 octets)');

        (new ExtracteurFacturX(1000))->extract(self::pdf(Fixtures::read('cii/complete.xml'), compress: false));
    }

    public function testTheLimitMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExtracteurFacturX(0);
    }

    public function testAMissingFileIsReported(): void
    {
        $this->expectException(InvalidPdf::class);
        $this->expectExceptionMessage('Le fichier « absent.pdf » est introuvable ou illisible.');

        (new ExtracteurFacturX())->extractFromFile('absent.pdf');
    }

    public function testTheGeneratorProducesTheCommittedFixtures(): void
    {
        $directory = sys_get_temp_dir() . '/facturx-' . bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(Fixtures::path('generer-pdf-facturx.php')) . ' ' . escapeshellarg($directory);
            exec($command . ' 2>&1', $output, $status);
            self::assertSame(0, $status, implode("\n", $output));

            $extractor = new ExtracteurFacturX();
            foreach (['facturx-en16931.pdf', 'facturx-basic-flux-objets.pdf'] as $name) {
                self::assertSame(
                    $extractor->extractFromFile(Fixtures::path('pdf/' . $name))->xml,
                    $extractor->extractFromFile($directory . '/' . $name)->xml,
                );
            }
            self::assertSame(
                $extractor->extractFromFile(__DIR__ . '/../examples/facture-exemple.pdf')->xml,
                $extractor->extractFromFile($directory . '/facture-exemple.pdf')->xml,
            );
        } finally {
            array_map('unlink', glob($directory . '/*') ?: []);
            rmdir($directory);
        }
    }

    /**
     * PDF minimal avec un fichier joint, aux variantes réglables.
     */
    private static function pdf(
        string $xml,
        string $fileSpecName = '(factur-x.xml)',
        bool $compress = true,
        string $filter = '/FlateDecode',
        string $trailerExtra = '',
        ?string $embeddedReference = null,
        bool $corrupt = false,
    ): string {
        $pdf = new PdfBuilder();
        $catalog = $pdf->reserve();
        $embedded = $pdf->reserve();
        $data = $compress ? (string) gzcompress($xml) : $xml;
        if ($corrupt) {
            $data = substr($data, 0, 2) . str_repeat("\xFF", 40) . substr($data, 42);
        }
        $pdf->set($embedded, PdfBuilder::stream('/Type /EmbeddedFile' . ($compress ? ' /Filter ' . $filter : ''), $data));
        $fileSpec = $pdf->add(sprintf('<< /Type /Filespec /F %s /EF << /F %s >> >>', $fileSpecName, $embeddedReference ?? $embedded . ' 0 R'));
        // Commentaire et faux objet : le parcours doit les ignorer.
        $pdf->add("% commentaire\n<< /Faux 1 0 obj >>");
        $pdf->set($catalog, sprintf('<< /Type /Catalog /Names << /EmbeddedFiles << /Names [(factur-x.xml) %d 0 R] >> >> >>', $fileSpec));

        return $pdf->build($catalog, null, [], $trailerExtra);
    }
}
