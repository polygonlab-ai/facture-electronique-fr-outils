<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PolygonLab\FactureElectronique\ControleMentions;
use PolygonLab\FactureElectronique\Facture;
use PolygonLab\FactureElectronique\Format;
use PolygonLab\FactureElectronique\Partie;

// Facture construite à la main, volontairement incomplète.
$facture = new Facture(
    format: Format::Cii,
    number: 'F-2026-0099',
    issueDate: new DateTimeImmutable('2026-10-01'),
    currency: 'EUR',
    seller: new Partie(name: 'Atelier Fictif SAS', vatNumber: 'FR48333333334'),
    buyer: new Partie(name: 'Client Fictif SARL'),
);

// Vendeur qui a opté pour le paiement de la taxe d'après les débits : la mention est alors exigée.
$controle = new ControleMentions(optionDebits: true);
$rapport = $controle->check($facture, new DateTimeImmutable('2027-10-01'));

foreach ($rapport->violations as $manque) {
    echo str_pad($manque->code(), 30), ' attendu depuis le ', $manque->applicableFrom->format('d/m/Y'), "\n";
}
echo 'Codes : ', implode(', ', $rapport->codes()), "\n";
