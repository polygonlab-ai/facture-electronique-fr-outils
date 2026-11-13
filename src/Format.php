<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique;

/**
 * Syntaxe XML dont la facture a été lue.
 */
enum Format: string
{
    /** UN/CEFACT Cross Industry Invoice, la syntaxe embarquée dans Factur-X. */
    case Cii = 'CII';

    /** OASIS Universal Business Language 2.1 (Invoice ou CreditNote). */
    case Ubl = 'UBL';
}
