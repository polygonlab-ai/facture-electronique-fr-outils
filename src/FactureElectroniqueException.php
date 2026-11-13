<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique;

/**
 * Interface commune à toutes les exceptions du paquet : un seul « catch » suffit pour les intercepter toutes.
 */
interface FactureElectroniqueException extends \Throwable {}
