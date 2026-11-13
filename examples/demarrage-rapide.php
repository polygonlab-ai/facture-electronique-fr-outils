<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PolygonLab\FactureElectronique\ControleMentions;
use PolygonLab\FactureElectronique\ExtracteurFacturX;

// 1. Extraire le XML CII du PDF Factur-X, puis lire la facture.
$document = (new ExtracteurFacturX())->extractFromFile(__DIR__ . '/facture-exemple.pdf');
$facture = $document->facture();

echo 'Profil Factur-X : ', $document->profile?->value, "\n";
echo 'Facture ', $facture->number, ' du ', $facture->issueDate?->format('d/m/Y'), "\n";
echo 'Vendeur : ', $facture->seller->name, ' (SIREN ', $facture->seller->siren()?->format(), ")\n";
echo 'Total TTC : ', $facture->totaux->taxInclusive, ' ', $facture->currency, "\n";

// 2. Contrôler les mentions obligatoires à trois dates : chaque donnée a sa date d'application.
$controle = new ControleMentions();
foreach (['2026-08-31', '2026-09-01', '2027-09-01'] as $date) {
    $rapport = $controle->check($facture, new DateTimeImmutable($date));
    echo "\nContrôle au ", $rapport->referenceDate->format('d/m/Y'), ' : ';
    echo $rapport->isCompliant() ? 'conforme' : count($rapport->violations) . ' manque(s)', "\n";
    foreach ($rapport->violations as $manque) {
        echo '- ', $manque->code(), ' (attendu depuis le ', $manque->applicableFrom->format('d/m/Y'), ') : ', $manque->message, "\n";
    }
}
