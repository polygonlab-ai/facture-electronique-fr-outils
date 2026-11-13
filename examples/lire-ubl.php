<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PolygonLab\FactureElectronique\LecteurUbl;

// Invoice ou CreditNote : le lecteur reconnaît les deux.
$facture = (new LecteurUbl())->readFile(__DIR__ . '/facture-ubl.xml');

echo $facture->format->value, ' ', $facture->number, $facture->isCreditNote() ? ' (avoir)' : ' (facture)', "\n";
echo 'Vendeur : ', $facture->seller->name, ', TVA ', $facture->seller->numeroTvaFrancais()?->format(), "\n";
// Client identifié par son SIRET (schéma 0009) : le SIREN en est déduit.
echo 'Client : ', $facture->buyer->name, ', SIREN ', $facture->buyer->siren()?->format(), "\n";
echo 'Livraison : ', $facture->deliveryAddress?->format(), "\n";
echo 'Total HT ', $facture->totaux->taxExclusive, ', TVA ', $facture->totaux->taxTotal, ', TTC ', $facture->totaux->taxInclusive, "\n";
