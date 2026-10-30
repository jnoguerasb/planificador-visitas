<?php

declare(strict_types=1);

namespace App;

/**
 * Huecos libres de la jornada (08:00–17:00). Cada visita activa ocupa desde que el técnico sale
 * hacia ella (inicio − desplazamiento) hasta que acaba. Clase pura: no toca la base de datos.
 */
final class CalculadoraAgenda
{
    /**
     * @param list<Visita> $visitas Visitas del técnico ese día, en cualquier estado.
     * @return list<Hueco>
     */
    public function huecos(array $visitas): array
    {
        $ocupados = [];
        foreach ($visitas as $visita) {
            if ($visita->estaActiva()) {
                $ocupados[] = [max($visita->salida(), Reglas::JORNADA_INICIO), min($visita->fin(), Reglas::JORNADA_FIN)];
            }
        }
        usort($ocupados, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $huecos = [];
        $libreDesde = Reglas::JORNADA_INICIO;
        foreach ($ocupados as [$desde, $hasta]) {
            if ($desde > $libreDesde) {
                $huecos[] = new Hueco($libreDesde, $desde);
            }
            $libreDesde = max($libreDesde, $hasta);
        }
        if ($libreDesde < Reglas::JORNADA_FIN) {
            $huecos[] = new Hueco($libreDesde, Reglas::JORNADA_FIN);
        }

        return $huecos;
    }
}
