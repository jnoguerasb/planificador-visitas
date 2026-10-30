<?php

declare(strict_types=1);

namespace App;

/**
 * Al cancelar una visita, la que salía de ella pasa a salir de la visita activa anterior a la
 * cancelada, o de la sede. Su desplazamiento no cambia, pero queda para revisar. Nunca bloquea.
 */
final class ReasignadorOrigen
{
    /**
     * @param list<Visita> $visitasDelDia Visitas del técnico ese día, en cualquier estado.
     */
    public function trasCancelar(Visita $cancelada, array $visitasDelDia): ?CambioOrigen
    {
        $dependiente = null;
        $nuevoOrigen = null;

        foreach ($visitasDelDia as $visita) {
            if ($visita->id === $cancelada->id || !$visita->estaActiva()) {
                continue;
            }
            if ($visita->origenVisitaId === $cancelada->id) {
                $dependiente = $visita;
            }
            if ($visita->inicio < $cancelada->inicio && ($nuevoOrigen === null || $visita->inicio > $nuevoOrigen->inicio)) {
                $nuevoOrigen = $visita;
            }
        }

        if ($dependiente === null || $dependiente->id === null) {
            return null;
        }

        return new CambioOrigen($dependiente->id, $nuevoOrigen?->id);
    }
}
