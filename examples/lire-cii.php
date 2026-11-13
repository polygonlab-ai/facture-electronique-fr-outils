<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PolygonLab\FactureElectronique\LecteurCii;

$facture = (new LecteurCii())->readFile(__DIR__ . '/facture-cii.xml');

echo $facture->format->value, ' ', $facture->number, ' (', $facture->categorieOperation?->label(), ")\n";
echo 'Client : ', $facture->buyer->name, ', SIREN ', $facture->buyer->siren()?->toString(), "\n";
foreach ($facture->lines as $ligne) {
    echo $ligne->id, '. ', $ligne->name, ' : ', $ligne->quantity, ' x ', $ligne->netPrice, ' = ', $ligne->netAmount, "\n";
}
foreach ($facture->vatBreakdowns as $tva) {
    echo 'TVA ', $tva->rate, ' % sur ', $tva->taxableAmount, ' : ', $tva->taxAmount, "\n";
}
echo 'Option sur les débits : ', $facture->hasOptionDebitsMention() ? 'oui' : 'non', "\n";
