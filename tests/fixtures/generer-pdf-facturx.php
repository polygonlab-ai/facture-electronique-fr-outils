<?php

declare(strict_types=1);

/**
 * Génère les PDF Factur-X de test à partir des factures CII du dossier cii/.
 *
 * Usage :
 *   php tests/fixtures/generer-pdf-facturx.php            écrit dans tests/fixtures/pdf/ et examples/
 *   php tests/fixtures/generer-pdf-facturx.php <dossier>  écrit tous les PDF dans <dossier>
 *
 * Les PDF sont fabriqués ici, octet par octet : aucun n'est téléchargé ni copié d'ailleurs.
 * Ce sont des PDF minimaux (une page, un fichier joint), pas des PDF/A-3 complets.
 */

require __DIR__ . '/../../vendor/autoload.php';

use PolygonLab\FactureElectronique\Tests\Support\PdfBuilder;

/**
 * PDF d'une page avec, si $xml est fourni, le fichier joint factur-x.xml.
 *
 * @param bool $objectStream objets rangés dans un flux d'objets (PDF 1.5 et plus), longueur indirecte
 */
function facturXPdf(?string $xml, string $title, string $conformance, bool $objectStream = false): string
{
    $pdf = new PdfBuilder();
    $catalog = $pdf->reserve();
    $pages = $pdf->reserve();
    $page = $pdf->reserve();
    $font = $pdf->add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
    $text = sprintf("BT /F1 16 Tf 72 770 Td (%s) Tj ET\nBT /F1 10 Tf 72 745 Td (Document de test, societes fictives.) Tj ET", $title);
    $contents = $pdf->add(PdfBuilder::stream('', $text));
    $pdf->set($pages, sprintf('<< /Type /Pages /Kids [%d 0 R] /Count 1 >>', $page));
    $pdf->set($page, sprintf('<< /Type /Page /Parent %d 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 %d 0 R >> >> /Contents %d 0 R >>', $pages, $font, $contents));
    $info = $pdf->add(sprintf('<< /Title (%s) /Producer (generer-pdf-facturx.php) /CreationDate (D:20261015000000Z) >>', $title));

    $inObjectStream = [$catalog, $pages, $page, $font];
    if ($xml === null) {
        $pdf->set($catalog, sprintf('<< /Type /Catalog /Pages %d 0 R >>', $pages));

        return $pdf->build($catalog, $info, $objectStream ? $inObjectStream : []);
    }

    $compressed = (string) gzcompress($xml, 9);
    $embedded = $pdf->reserve();
    $params = sprintf('/Type /EmbeddedFile /Subtype /text#2Fxml /Params << /Size %d /ModDate (D:20261015000000Z) >> /Filter /FlateDecode', strlen($xml));
    if ($objectStream) {
        // Longueur écrite dans un objet à part (référence indirecte), comme le font certains logiciels.
        $length = $pdf->add((string) strlen($compressed));
        $pdf->set($embedded, PdfBuilder::stream($params, $compressed, $length . ' 0 R'));
        $fileSpec = $pdf->add(sprintf('<< /Type /Filespec /UF %s /Desc (Facture Factur-X) /AFRelationship /Data /EF << /F %d 0 R /UF %d 0 R >> >>', PdfBuilder::utf16Hex('factur-x.xml'), $embedded, $embedded));
        $inObjectStream[] = $length;
    } else {
        $pdf->set($embedded, PdfBuilder::stream($params, $compressed));
        $fileSpec = $pdf->add(sprintf('<< /Type /Filespec /F (factur-x.xml) /UF %s /Desc (Facture Factur-X) /AFRelationship /Data /EF << /F %d 0 R /UF %d 0 R >> >>', PdfBuilder::utf16Hex('factur-x.xml'), $embedded, $embedded));
    }
    $names = $pdf->add(sprintf('<< /Names [(factur-x.xml) %d 0 R] >>', $fileSpec));
    $xmp = <<<XMP
        <?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>
        <x:xmpmeta xmlns:x="adobe:ns:meta/">
         <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
          <rdf:Description rdf:about="" xmlns:fx="urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#">
           <fx:DocumentType>INVOICE</fx:DocumentType>
           <fx:DocumentFileName>factur-x.xml</fx:DocumentFileName>
           <fx:Version>1.0</fx:Version>
           <fx:ConformanceLevel>{$conformance}</fx:ConformanceLevel>
          </rdf:Description>
         </rdf:RDF>
        </x:xmpmeta>
        <?xpacket end="w"?>
        XMP;
    $metadata = $pdf->add(PdfBuilder::stream('/Type /Metadata /Subtype /XML', $xmp));
    $pdf->set($catalog, sprintf(
        '<< /Type /Catalog /Pages %d 0 R /Names << /EmbeddedFiles %d 0 R >> /AF [%d 0 R] /Metadata %d 0 R /PageMode /UseAttachments >>',
        $pages,
        $names,
        $fileSpec,
        $metadata,
    ));
    $inObjectStream[] = $fileSpec;
    $inObjectStream[] = $names;

    return $pdf->build($catalog, $info, $objectStream ? $inObjectStream : []);
}

function readFixture(string $name): string
{
    $xml = file_get_contents(__DIR__ . '/cii/' . $name);
    if ($xml === false) {
        throw new RuntimeException('Jeu d\'essai introuvable : ' . $name);
    }

    return $xml;
}

$complete = readFixture('complete.xml');
$basic = str_replace(
    '<ram:ID>urn:cen.eu:en16931:2017</ram:ID>',
    '<ram:ID>urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:basic</ram:ID>',
    $complete,
);

$target = $argv[1] ?? null;
$files = [
    'pdf/facturx-en16931.pdf' => facturXPdf($complete, 'Facture F-2026-0042', 'EN 16931'),
    'pdf/facturx-basic-flux-objets.pdf' => facturXPdf($basic, 'Facture F-2026-0042', 'BASIC', true),
    'pdf/sans-piece-jointe.pdf' => facturXPdf(null, 'PDF sans fichier joint', 'EN 16931'),
    '../../examples/facture-exemple.pdf' => facturXPdf(readFixture('exemple-demarrage-rapide.xml'), 'Facture F-2026-0042', 'EN 16931'),
];

foreach ($files as $path => $content) {
    $destination = $target === null ? __DIR__ . '/' . $path : rtrim($target, '/') . '/' . basename($path);
    if (file_put_contents($destination, $content) === false) {
        fwrite(STDERR, 'Écriture impossible : ' . $destination . "\n");
        exit(1);
    }
    echo basename($destination), ' : ', strlen($content), " octets\n";
}
