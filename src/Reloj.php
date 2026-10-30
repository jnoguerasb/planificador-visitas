<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Hora actual de la aplicación, siempre en Europe/Madrid, sea cual sea la zona del servidor
 * (Render funciona en UTC). En los tests se crea con una hora fija.
 */
final class Reloj
{
    public const ZONA_HORARIA = 'Europe/Madrid';

    public function __construct(private readonly ?DateTimeImmutable $fijo = null)
    {
    }

    public function ahora(): DateTimeImmutable
    {
        $zona = new DateTimeZone(self::ZONA_HORARIA);

        return $this->fijo?->setTimezone($zona) ?? new DateTimeImmutable('now', $zona);
    }
}
