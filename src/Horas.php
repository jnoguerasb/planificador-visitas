<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

/** Conversión entre "HH:MM" y minutos desde medianoche ("10:30" ↔ 630). */
final class Horas
{
    public static function esValida(mixed $hora): bool
    {
        return is_string($hora) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $hora) === 1;
    }

    public static function aMinutos(string $hora): int
    {
        if (!self::esValida($hora)) {
            throw new InvalidArgumentException('Hora no válida, se espera HH:MM');
        }

        return (int) substr($hora, 0, 2) * 60 + (int) substr($hora, 3, 2);
    }

    public static function aTexto(int $minutos): string
    {
        if ($minutos < 0 || $minutos >= 24 * 60) {
            throw new InvalidArgumentException('Minutos fuera del día');
        }

        return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
    }
}
