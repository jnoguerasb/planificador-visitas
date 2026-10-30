<?php

declare(strict_types=1);

namespace App;

/**
 * Una visita tal como la usan las reglas. Horas en minutos desde medianoche.
 * El origen es la sede si $origenVisitaId es null.
 */
final readonly class Visita
{
    public const PROGRAMADA = 'Programada';
    public const COMPLETADA = 'Completada';
    public const CANCELADA = 'Cancelada';

    public const ORIGEN_SEDE = 'SEDE';
    public const ORIGEN_VISITA = 'VISITA';

    public function __construct(
        public ?int $id,
        public int $tecnicoId,
        public int $obraId,
        public string $obraNombre,
        public string $fecha,
        public int $inicio,
        public int $duracion,
        public int $desplazamiento,
        public string $estado = self::PROGRAMADA,
        public ?int $origenVisitaId = null,
        public bool $revisarDesplazamiento = false,
    ) {
    }

    public function fin(): int
    {
        return $this->inicio + $this->duracion;
    }

    /** Hora a la que sale el técnico hacia esta visita. */
    public function salida(): int
    {
        return $this->inicio - $this->desplazamiento;
    }

    /** Programadas y Completadas ocupan horario; las Canceladas no. */
    public function estaActiva(): bool
    {
        return $this->estado === self::PROGRAMADA || $this->estado === self::COMPLETADA;
    }

    public function origenTipo(): string
    {
        return $this->origenVisitaId === null ? self::ORIGEN_SEDE : self::ORIGEN_VISITA;
    }
}
