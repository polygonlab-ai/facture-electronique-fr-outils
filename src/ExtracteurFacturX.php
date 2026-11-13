<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique;

use PolygonLab\FactureElectronique\Internal\Pdf\PdfDictionary;
use PolygonLab\FactureElectronique\Internal\Pdf\PdfDocument;
use PolygonLab\FactureElectronique\Internal\Pdf\PdfReference;
use PolygonLab\FactureElectronique\Internal\Pdf\PdfStream;
use PolygonLab\FactureElectronique\Internal\Pdf\PdfString;
use PolygonLab\FactureElectronique\Internal\Pdf\StreamDecoder;
use PolygonLab\FactureElectronique\Internal\Xml\SafeXml;

/**
 * Extrait le XML CII embarqué dans un PDF Factur-X (fichier joint « factur-x.xml ») et détecte le
 * profil (MINIMUM, BASIC WL, BASIC, EN 16931, EXTENDED), sans bibliothèque PDF externe.
 *
 * Le PDF est lu objet par objet ; les flux compressés FlateDecode et les flux d'objets (PDF 1.5 et
 * plus) sont pris en charge. La conformité PDF/A-3 du fichier n'est pas vérifiée.
 */
final class ExtracteurFacturX
{
    public const ATTACHMENT_NAME = 'factur-x.xml';

    /** Taille maximale d'un flux décompressé par défaut : 20 Mio. */
    public const DEFAULT_MAX_BYTES = 20 * 1024 * 1024;

    private readonly StreamDecoder $decoder;

    /**
     * @param int $maxBytes taille maximale, en octets, d'un flux une fois décompressé
     */
    public function __construct(int $maxBytes = self::DEFAULT_MAX_BYTES)
    {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('La taille maximale d\'un flux doit être d\'au moins 1 octet.');
        }
        $this->decoder = new StreamDecoder($maxBytes);
    }

    /**
     * @param string $pdf contenu binaire du PDF
     *
     * @throws InvalidPdf si le PDF n'est pas un Factur-X lisible
     */
    public function extract(string $pdf): FacturXDocument
    {
        $document = PdfDocument::parse($pdf, $this->decoder);
        if ($document->isEncrypted()) {
            throw InvalidPdf::encrypted();
        }

        $fileSpec = $this->findFileSpec($document);
        if ($fileSpec === null) {
            throw InvalidPdf::noFacturXAttachment();
        }

        $embedded = $document->resolve($fileSpec->get('EF'));
        $reference = $embedded instanceof PdfDictionary ? ($embedded->get('F') ?? $embedded->get('UF')) : null;
        $stream = $document->resolve($reference);
        if (!$stream instanceof PdfStream) {
            throw InvalidPdf::missingStream($reference instanceof PdfReference ? $reference->toString() : 'non référencé');
        }

        return $this->document($this->decoder->decode($stream, $document));
    }

    /**
     * @throws InvalidPdf si le fichier est illisible ou n'est pas un Factur-X lisible
     */
    public function extractFromFile(string $path): FacturXDocument
    {
        $pdf = is_file($path) ? @file_get_contents($path) : false;
        if ($pdf === false) {
            throw InvalidPdf::unreadableFile($path);
        }

        return $this->extract($pdf);
    }

    /**
     * Dernière spécification de fichier joint (dictionnaire avec /EF) nommée factur-x.xml.
     */
    private function findFileSpec(PdfDocument $document): ?PdfDictionary
    {
        $found = null;
        foreach ($document->dictionaries() as $dictionary) {
            if (!$dictionary->has('EF')) {
                continue;
            }
            foreach (['UF', 'F'] as $key) {
                $name = $document->resolve($dictionary->get($key));
                if ($name instanceof PdfString && strtolower(trim($name->text())) === self::ATTACHMENT_NAME) {
                    $found = $dictionary;
                    break;
                }
            }
        }

        return $found;
    }

    private function document(string $xml): FacturXDocument
    {
        try {
            $doc = SafeXml::load($xml, ['rsm' => LecteurCii::NS_RSM, 'ram' => LecteurCii::NS_RAM]);
        } catch (InvalidDocument $e) {
            throw InvalidPdf::notCii($e->getMessage());
        }
        $root = $doc->root();
        if ($root->namespaceURI !== LecteurCii::NS_RSM || $root->localName !== 'CrossIndustryInvoice') {
            throw InvalidPdf::notCii(sprintf('l\'élément racine est « %s » au lieu de CrossIndustryInvoice.', $root->localName ?? $root->nodeName));
        }
        $guidelineId = $doc->text('/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID');

        return new FacturXDocument($xml, $guidelineId, FacturXProfile::fromGuidelineId($guidelineId));
    }
}
