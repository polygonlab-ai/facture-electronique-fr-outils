# Journal des changements

Toutes les évolutions notables de ce dépôt sont décrites ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet respecte
le [versionnage sémantique](https://semver.org/lang/fr/).

## [0.1.1] - 2026-11-14 - Correction de l'analyse statique des tests

### Corrigé

- `tests/ReadmeTest.php` : la présence de la sortie attendue dans le README est vérifiée avant sa lecture, ce qui
  satisfait PHPStan 2.3 au niveau maximal. Aucun changement de la bibliothèque.

## [0.1.0] - 2026-11-13 - Lecture CII, UBL et Factur-X, contrôle des mentions obligatoires

### Ajouté

- `Facture` et ses objets (`Partie`, `Adresse`, `Ligne`, `VentilationTva`, `Totaux`, `CategorieOperation`) : modèle
  neutre et immuable d'une facture, aux champs nommés d'après les termes de la norme EN 16931.
- `LecteurCii` : lecture des factures UN/CEFACT Cross Industry Invoice (CII), la syntaxe de Factur-X.
- `LecteurUbl` : lecture des factures et avoirs OASIS UBL 2.1 (`Invoice` et `CreditNote`).
- `ExtracteurFacturX` : extraction du fichier `factur-x.xml` joint à un PDF Factur-X, sans bibliothèque PDF
  (flux FlateDecode, flux d'objets), et détection du profil (MINIMUM, BASIC WL, BASIC, EN 16931, EXTENDED).
- `ControleMentions` : contrôle des quatre mentions ajoutées par le décret n° 2022-1299 du 7 octobre 2022 et des
  mentions classiques, à une date de référence ; chaque règle porte la date à partir de laquelle la donnée est attendue
  (1er septembre 2026, ou 1er septembre 2027 pour les données des lignes et l'adresse de livraison), selon la page
  « données de facture » de l'administration fiscale ; codes stables et messages en français.
- SIREN et numéro de TVA lus avec les objets valeur de `polygonlab/identifiants-entreprise-fr`.
- Exceptions `InvalidDocument` et `InvalidPdf`, avec un message en français, réunies sous `FactureElectroniqueException`.
- Lecture sûre : XML sans DTD ni entités externes, aucun accès réseau, décompression bornée.

[0.1.1]: https://github.com/polygonlab-ai/facture-electronique-fr-outils/releases/tag/v0.1.1
[0.1.0]: https://github.com/polygonlab-ai/facture-electronique-fr-outils/releases/tag/v0.1.0

---
— Conçu et développé par PolygonLab · polygonlab.ai · Licence MIT
