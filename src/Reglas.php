<?php

declare(strict_types=1);

namespace App;

/** Constantes de negocio. Todas las horas en minutos desde medianoche (08:00 = 480). */
final class Reglas
{
    public const JORNADA_INICIO = 480;
    public const JORNADA_FIN = 1020;
    public const DESPLAZAMIENTO_MINIMO = 30;
    public const DESPLAZAMIENTO_MAXIMO = 240;
    public const DURACION_MINIMA = 15;
    // Con el mínimo de 30 desde la sede (R2) la jornada útil empieza a las 08:30, así que 540 no cabe
    // y el máximo real es 510. Se mantiene 540: si R2 cambia, vuelve a caber sin tocar nada.
    public const DURACION_MAXIMA = 540;
    public const COMENTARIO_MINIMO = 10;
    public const MOTIVO_MINIMO = 5;
    public const TEXTO_MAXIMO = 2000;

    /**
     * Desplazamiento mínimo para ir de una obra a otra. $obraOrigenId es null si se sale de la sede;
     * $obraDestinoId es null si aún no se conoce (obra no válida o sin visita siguiente).
     *
     * Puntos R1 y R2 del análisis, pendientes de decisión: hoy el mínimo es 30 siempre, también
     * en la misma obra (R1) y desde la sede (R2). Si negocio decide otra cosa, solo cambia este método.
     */
    public static function desplazamientoMinimo(?int $obraOrigenId, ?int $obraDestinoId): int
    {
        return self::DESPLAZAMIENTO_MINIMO;
    }
}
