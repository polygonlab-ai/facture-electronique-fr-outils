<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PolygonLab\FactureElectronique\ExtracteurFacturX;
use PolygonLab\FactureElectronique\InvalidPdf;

$extracteur = new ExtracteurFacturX();

$document = $extracteur->extractFromFile(__DIR__ . '/facture-exemple.pdf');
echo 'Profil : ', $document->profile?->value, ' (', $document->guidelineId, ")\n";
echo 'Lignes portées par le profil : ', $document->profile?->hasLines() ? 'oui' : 'non', "\n";
echo 'XML extrait : ', strlen($document->xml), " octets\n";

// Un PDF sans fichier factur-x.xml joint : l'erreur dit pourquoi.
try {
    $extracteur->extract("%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
} catch (InvalidPdf $e) {
    echo $e->getMessage(), "\n";
}
