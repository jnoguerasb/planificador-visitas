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
    // 540 min es la jornada entera: cabe si se sale de la sede a las 08:00 sin desplazamiento.
    public const DURACION_MAXIMA = 540;
    public const COMENTARIO_MINIMO = 10;
    public const MOTIVO_MINIMO = 5;
    public const TEXTO_MAXIMO = 2000;

    /**
     * Desplazamiento mínimo para llegar a $obraId. $obraAnteriorId es null si se sale de la sede.
     *
     * Regla b del enunciado: 30 min entre visitas a obras diferentes: en la misma obra
     * no hay mínimo y desde la sede tampoco, así que una visita puede empezar a las 08:00. Si se indica un
     * desplazamiento desde la sede, la salida sigue sin poder ser antes de las 08:00.
     */
    public static function desplazamientoMinimo(?int $obraAnteriorId, int $obraId): int
    {
        if ($obraAnteriorId === null || $obraAnteriorId === $obraId) {
            return 0;
        }

        return self::DESPLAZAMIENTO_MINIMO;
    }
}
