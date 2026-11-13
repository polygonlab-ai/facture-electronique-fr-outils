```
       ▄▄▀▀▄▄        ____       _                         _          _
    ▄▀▀  ▄▄  ▀▀▄    |  _ \ ___ | |_   _  __ _  ___  _ __ | |    __ _| |__
    █  ▄▀  ▀▄  █    | |_) / _ \| | | | |/ _` |/ _ \| '_ \| |   / _` | '_ \
    █  ▀▄  ▄▀  █    |  __/ (_) | | |_| | (_| | (_) | | | | |__| (_| | |_) |
    ▀▄▄  ▀▀  ▄▄▀    |_|   \___/|_|\__, |\__, |\___/|_| |_|_____\__,_|_.__/
       ▀▀▄▄▀▀                     |___/ |___/
                                   polygonlab.ai
```

# facture-electronique-fr-outils

> Lecture des factures électroniques CII, UBL et Factur-X et contrôle des mentions obligatoires françaises, pour les applications PHP.

[![CI](https://github.com/polygonlab-ai/facture-electronique-fr-outils/actions/workflows/ci.yml/badge.svg)](https://github.com/polygonlab-ai/facture-electronique-fr-outils/actions/workflows/ci.yml)
[![Version](https://img.shields.io/packagist/v/polygonlab/facture-electronique-fr-outils)](https://packagist.org/packages/polygonlab/facture-electronique-fr-outils)
[![Licence](https://img.shields.io/badge/licence-MIT-blue)](LICENSE)
[![PHP](https://img.shields.io/packagist/dependency-v/polygonlab/facture-electronique-fr-outils/php)](composer.json)

## Pourquoi

Entre le 1er septembre 2026 et le 1er septembre 2027, toutes les entreprises françaises passent à la facture
électronique, et quatre mentions s'ajoutent aux factures : le SIREN du client, l'adresse de livraison si elle diffère
de l'adresse de facturation, la catégorie de l'opération et, le cas échéant, l'option pour le paiement de la taxe
d'après les débits. Cette bibliothèque lit une facture reçue ou émise, qu'elle soit en CII, en UBL ou dans un PDF
Factur-X, la ramène à un modèle unique, et dit en français ce qui lui manque à une date donnée. Elle n'a besoin
d'aucune bibliothèque PDF ni d'aucun service extérieur.

## Fonctionnalités

- Lecture des factures CII (UN/CEFACT, la syntaxe de Factur-X) et UBL 2.1 (`Invoice` et `CreditNote`).
- Extraction du XML embarqué dans un PDF Factur-X, sans bibliothèque PDF, et détection du profil (MINIMUM, BASIC WL, BASIC, EN 16931, EXTENDED).
- Modèle `Facture` neutre et immuable, aux champs nommés d'après les termes de la norme EN 16931 (BT-…).
- SIREN et numéro de TVA lus avec les objets valeur de [identifiants-entreprise-fr](https://github.com/polygonlab-ai/identifiants-entreprise-fr).
- Contrôle des mentions obligatoires à une date de référence : codes stables, messages en français, date d'application propre à chaque donnée.
- Lecture sûre : XML sans DTD ni entités externes, aucun accès réseau, décompression bornée.
- PHP 8.2 ou plus ; extensions `dom`, `libxml` et `zlib`.

## Installation

```bash
composer require polygonlab/facture-electronique-fr-outils
```

## Démarrage rapide

Le script lit un PDF Factur-X, puis contrôle ses mentions à trois dates : chaque donnée de la facture a sa propre date
d'application. Pour l'essayer, placez à côté du script le PDF d'exemple [`examples/facture-exemple.pdf`](examples/facture-exemple.pdf)
(facture fictive), ou remplacez son nom par celui de votre facture.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

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
```

Sortie attendue (ce script est aussi dans [`examples/demarrage-rapide.php`](examples/demarrage-rapide.php), et un test vérifie qu'il produit exactement cette sortie) :

```text
Profil Factur-X : EN 16931
Facture F-2026-0042 du 15/10/2026
Vendeur : Atelier Fictif SAS (SIREN 333 333 334)
Total TTC : 1440.00 EUR

Contrôle au 31/08/2026 : conforme

Contrôle au 01/09/2026 : 1 manque(s)
- SIREN_CLIENT_ABSENT (attendu depuis le 01/09/2026) : Le SIREN du client (BT-47, schéma 0002) est absent.

Contrôle au 01/09/2027 : 2 manque(s)
- SIREN_CLIENT_ABSENT (attendu depuis le 01/09/2026) : Le SIREN du client (BT-47, schéma 0002) est absent.
- ADRESSE_LIVRAISON_INCOMPLETE (attendu depuis le 01/09/2027) : L'adresse de livraison (BG-15) est incomplète : ligne, code postal, ville et pays sont attendus.
```

## Utilisation

### Mentions contrôlées

Les dates sont celles de la page [« données de facture »](https://www.impots.gouv.fr/facturation-electronique-donnees-de-facture)
de l'administration fiscale, qui indique pour chaque donnée la date à partir de laquelle elle est attendue. Elles
dépendent de la donnée, pas de la taille de l'entreprise. Cette page s'appuie sur l'article 289 bis du code général des
impôts (CGI), l'article 41 septies D de l'annexe IV au CGI, l'article 242 nonies A de l'annexe II au CGI et, pour le
SIREN, l'article R.123-221 du code de commerce.

| Mention | Code stable | Termes EN 16931 | Attendue à partir du | Condition |
|---|---|---|---|---|
| SIREN du client | `SIREN_CLIENT_ABSENT`, `SIREN_CLIENT_INVALIDE` | BT-47 (schéma 0002, ou SIRET en schéma 0009) | 01/09/2026 | Client établi en France |
| Catégorie de l'opération : biens (LB), services (PS) ou les deux (LBPS) | `CATEGORIE_OPERATION_ABSENTE` | BT-23 (voir plus bas) | 01/09/2026 | Toutes les factures |
| Mention « option pour le paiement de la taxe d'après les débits » | `MENTION_OPTION_DEBITS_ABSENTE` | BT-8 ou note BT-22 (voir plus bas) | 01/09/2026 | Seulement si le vendeur a opté |
| Adresse de livraison des biens | `ADRESSE_LIVRAISON_INCOMPLETE` | BG-15 | 01/09/2027 | Seulement si elle diffère de l'adresse du client |
| Désignation, quantité et prix unitaire hors taxe de chaque ligne | `DESIGNATION_ABSENTE`, `QUANTITE_ABSENTE`, `PRIX_UNITAIRE_ABSENT` | BT-153, BT-129, BT-146 | 01/09/2027 | |
| Lignes de la facture | `LIGNES_ABSENTES` | BG-25 | 01/09/2027 | |
| Numéro de facture | `NUMERO_ABSENT` | BT-1 | 01/09/2026 | |
| Date d'émission | `DATE_ABSENTE` | BT-2 | 01/09/2026 | |
| Nom et adresse du vendeur | `NOM_VENDEUR_ABSENT`, `ADRESSE_VENDEUR_INCOMPLETE` | BT-27, BG-5 | 01/09/2026 | |
| Numéro de TVA du vendeur | `TVA_VENDEUR_ABSENTE`, `TVA_VENDEUR_INVALIDE` | BT-31 | 01/09/2026 | Sauf facture entièrement exonérée ou hors champ |
| Nom et adresse du client | `NOM_CLIENT_ABSENT`, `ADRESSE_CLIENT_INCOMPLETE` | BT-44, BG-8 | 01/09/2026 | |
| Numéro de TVA du client | `TVA_CLIENT_ABSENTE` | BT-48 | 01/09/2026 | Opération intracommunautaire ou autoliquidée (catégories K et AE) |
| Taux de TVA | `TAUX_TVA_ABSENT` | BT-152, BT-119 | 01/09/2026 | Sauf opération hors champ (catégorie O) |
| Total hors taxe par taux et montant de la TVA | `VENTILATION_TVA_ABSENTE`, `BASE_TVA_ABSENTE`, `MONTANT_TVA_ABSENT` | BG-23, BT-116, BT-117, BT-110 | 01/09/2026 | |
| Totaux hors taxe et toutes taxes comprises | `TOTAL_HT_ABSENT`, `TOTAL_TTC_ABSENT` | BT-109, BT-112 | 01/09/2026 | |
| Devise | `DEVISE_ABSENTE` | BT-5 | 01/09/2026 | |

Une règle dont la date est postérieure à la date de référence du contrôle n'est pas signalée. La date de chaque règle
est aussi disponible dans le code : `Rule::SirenClientAbsent->applicableFrom()`.

Ces dates ne sont pas le calendrier de l'obligation d'émettre des factures électroniques, qui est une autre question :
les grandes entreprises et les entreprises de taille intermédiaire émettent à partir du 1er septembre 2026, les PME et
les micro-entreprises à partir du 1er septembre 2027 ; toutes doivent pouvoir recevoir des factures électroniques dès
le 1er septembre 2026.

Conditions appliquées :

- **SIREN du client** : exigé quand le client est établi en France, ou quand son pays n'est pas indiqué. Un client
  établi à l'étranger n'a pas de SIREN.
- **Adresse de livraison** : la bibliothèque ne peut pas savoir où les biens ont été livrés. Quand une adresse de
  livraison figure sur la facture et diffère de l'adresse du client, elle doit être complète (ligne, code postal, ville,
  pays).
- **Option sur les débits** : la bibliothèque ne peut pas savoir si le vendeur a opté. Vous le lui dites
  (`optionDebits: true`) ; la mention est alors exigée, sauf pour une facture de livraison de biens seule (LB), car
  l'option ne concerne que les prestations de services.

### Où la bibliothèque lit la catégorie et l'option sur les débits

La page de l'administration ne dit pas dans quels champs du XML ces deux mentions figurent. Les choix suivants sont une
**interprétation de la norme EN 16931**, pas une règle de l'administration :

- **Catégorie de l'opération** : lue dans le type de processus métier (BT-23), qui porte le cadre de facturation
  français : un code qui commence par B (biens, LB), S (services, PS) ou M (les deux, LBPS), suivi d'un chiffre, par
  exemple `B1`, `S1` ou `M1`. Toute autre valeur donne une catégorie inconnue, jamais devinée.
- **Option sur les débits** : reconnue quand le code de la date d'exigibilité de la TVA (BT-8) désigne la date de la
  facture (5 en CII, 3 en UBL), ou quand une note de la facture (BT-22) reprend la phrase « option pour le paiement de
  la taxe d'après les débits ».

### LecteurCii

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

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
```

```text
CII F-2026-0042 (prestation de services)
Client : Client Fictif SARL, SIREN 444444442
1. Développement d'un module de démonstration : 10 x 80.00 = 800.00
2. Hébergement mensuel : 1 x 400.00 = 400.00
TVA 20.00 % sur 1200.00 : 240.00
Option sur les débits : oui
```

### LecteurUbl

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use PolygonLab\FactureElectronique\LecteurUbl;

// Invoice ou CreditNote : le lecteur reconnaît les deux.
$facture = (new LecteurUbl())->readFile(__DIR__ . '/facture-ubl.xml');

echo $facture->format->value, ' ', $facture->number, $facture->isCreditNote() ? ' (avoir)' : ' (facture)', "\n";
echo 'Vendeur : ', $facture->seller->name, ', TVA ', $facture->seller->numeroTvaFrancais()?->format(), "\n";
// Client identifié par son SIRET (schéma 0009) : le SIREN en est déduit.
echo 'Client : ', $facture->buyer->name, ', SIREN ', $facture->buyer->siren()?->format(), "\n";
echo 'Livraison : ', $facture->deliveryAddress?->format(), "\n";
echo 'Total HT ', $facture->totaux->taxExclusive, ', TVA ', $facture->totaux->taxTotal, ', TTC ', $facture->totaux->taxInclusive, "\n";
```

```text
UBL F-2026-0107 (facture)
Vendeur : Mobilier Fictif SAS, TVA FR 72 666666664
Client : Bureau Exemple SA, SIREN 777 777 772
Livraison : 12 rue du Dépôt, 59100 Roubaix, FR
Total HT 700.00, TVA 140.00, TTC 840.00
```

### ExtracteurFacturX

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

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
```

```text
Profil : EN 16931 (urn:cen.eu:en16931:2017)
Lignes portées par le profil : oui
XML extrait : 6777 octets
Aucun fichier factur-x.xml n'est joint à ce PDF.
```

### ControleMentions

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

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
```

```text
SIREN_CLIENT_ABSENT            attendu depuis le 01/09/2026
CATEGORIE_OPERATION_ABSENTE    attendu depuis le 01/09/2026
MENTION_OPTION_DEBITS_ABSENTE  attendu depuis le 01/09/2026
ADRESSE_VENDEUR_INCOMPLETE     attendu depuis le 01/09/2026
ADRESSE_CLIENT_INCOMPLETE      attendu depuis le 01/09/2026
LIGNES_ABSENTES                attendu depuis le 01/09/2027
VENTILATION_TVA_ABSENTE        attendu depuis le 01/09/2026
TOTAL_HT_ABSENT                attendu depuis le 01/09/2026
MONTANT_TVA_ABSENT             attendu depuis le 01/09/2026
TOTAL_TTC_ABSENT               attendu depuis le 01/09/2026
Codes : SIREN_CLIENT_ABSENT, CATEGORIE_OPERATION_ABSENTE, MENTION_OPTION_DEBITS_ABSENTE, ADRESSE_VENDEUR_INCOMPLETE, ADRESSE_CLIENT_INCOMPLETE, LIGNES_ABSENTES, VENTILATION_TVA_ABSENTE, TOTAL_HT_ABSENT, MONTANT_TVA_ABSENT, TOTAL_TTC_ABSENT
```

Chaque manque (`Violation`) donne la règle (`$manque->rule`, une énumération `Rule`), le code stable
(`$manque->code()`), le message en français, la date à partir de laquelle la donnée est attendue
(`$manque->applicableFrom`) et, pour une règle qui porte sur une ligne, l'identifiant de la ligne (`$manque->lineId`).

### Erreurs

Toutes les exceptions du paquet implémentent `FactureElectroniqueException` :

| Exception | Levée par | Exemple de message |
|---|---|---|
| `InvalidDocument` | `LecteurCii`, `LecteurUbl` | « Le montant « 1 440,00 » (BT-112) n'est pas un nombre décimal. » |
| `InvalidPdf` | `ExtracteurFacturX` | « Aucun fichier factur-x.xml n'est joint à ce PDF. » |

### Limites

- **Pas de validation XSD ni Schematron** : les lecteurs lisent les termes présents sans vérifier le document contre les
  schémas ni contre les règles métier d'EN 16931 (calculs, cohérence des totaux, listes de codes).
- **Présence, pas exactitude** : le contrôle vérifie que les mentions figurent sur la facture, pas que les montants sont
  justes ni que le SIREN du client correspond à une entreprise existante.
- **Catégorie de l'opération** : si votre flux ne la porte pas en BT-23 comme décrit plus haut, donnez-la avec
  `$facture->withCategorieOperation(...)`.
- **Factur-X** : seul le fichier joint nommé `factur-x.xml` est lu (pas `zugferd-invoice.xml` ni `xrechnung.xml`). Les
  PDF chiffrés, les flux compressés autrement qu'en FlateDecode et les prédicteurs ne sont pas pris en charge. La
  conformité PDF/A-3 du fichier n'est pas vérifiée.
- **Mentions non contrôlées** : rabais, remises et ristournes, date de livraison ou d'exécution, conditions de paiement, pénalités de retard,
  indemnité forfaitaire de recouvrement, motif d'exonération, référence de la facture d'origine sur un avoir, mentions
  propres à certains régimes (franchise en base, autoliquidation, marge). Les données transmises à l'administration
  (e-reporting) ne sont pas contrôlées non plus.
- **Ne remplace pas une plateforme agréée** : l'émission et la réception des factures électroniques passent par une
  plateforme agréée par l'administration fiscale. Cette bibliothèque est un outil de lecture et de pré-contrôle ; elle
  ne remplace pas non plus l'avis d'un expert-comptable sur vos obligations.

## Configuration

| Option | Type | Valeur par défaut | Rôle |
|---|---|---|---|
| `new ExtracteurFacturX(int $maxBytes)` | `int` | `20971520` (20 Mio) | Taille maximale d'un flux PDF une fois décompressé |
| `new ControleMentions(bool $optionDebits)` | `bool` | `false` | Le vendeur a opté pour le paiement de la taxe d'après les débits |

## Compatibilité

PHP 8.2, 8.3 et 8.4, testés par l'intégration continue. Extensions `dom`, `libxml` et `zlib`, présentes dans la plupart
des installations de PHP.

## Tests

```bash
composer install
composer test      # PHPUnit
composer analyse   # PHPStan, niveau maximal
composer style     # PHP-CS-Fixer, PER Coding Style
```

Les jeux d'essai sont fabriqués : sociétés fictives, SIREN calculés pour être valides, montants inventés. Les PDF
Factur-X de test sont produits par le script [`tests/fixtures/generer-pdf-facturx.php`](tests/fixtures/generer-pdf-facturx.php).

## Feuille de route

- Écriture des factures en CII et en UBL, et conversion d'un format à l'autre.
- Génération de PDF Factur-X.
- Validation Schematron (règles EN 16931 et règles françaises).

## Sources

- Direction générale des finances publiques, [données de facture](https://www.impots.gouv.fr/facturation-electronique-donnees-de-facture) :
  liste des données et date à partir de laquelle chacune est attendue.
- Ministère de l'Économie, [tout savoir sur la facturation électronique](https://www.economie.gouv.fr/tout-savoir-sur-la-facturation-electronique-pour-les-entreprises) :
  calendrier de l'obligation d'émission et de réception.
- Code général des impôts, article 289 bis ; annexe IV au CGI, article 41 septies D ; annexe II au CGI, article
  242 nonies A ; code de commerce, article R.123-221 ; décret n° 2022-1299 du 7 octobre 2022.
- Norme européenne EN 16931 (modèle sémantique de la facture électronique), syntaxes UN/CEFACT CII et OASIS UBL 2.1,
  spécification Factur-X.

## Contribuer

Voir [CONTRIBUTING.md](https://github.com/polygonlab-ai/.github/blob/main/CONTRIBUTING.md).

## Sécurité

Une faille se signale en privé, jamais dans un ticket public : voir
[SECURITY.md](https://github.com/polygonlab-ai/.github/blob/main/SECURITY.md).

## Licence

Licence MIT : voir [LICENSE](LICENSE).

---
— Conçu et développé par PolygonLab · polygonlab.ai · Licence MIT
