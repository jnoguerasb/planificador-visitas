<?php

declare(strict_types=1);

namespace App;

/**
 * Resultado de validar una visita nueva.
 *
 * - $visita: la visita validada, con su origen calculado; null si hay errores de CAMPOS.
 * - $anterior / $siguiente: visitas activas inmediatamente antes y después (solo si la hora es válida).
 * - $desplazamientoSiguiente: el que tendrá la siguiente al guardar (el indicado o el sugerido).
 * - $desplazamientoMinimo: mínimo para llegar a la nueva (0 o 30); null si aún no se puede saber.
 * - $desplazamientoSiguienteSugerido: valor que se propone para la siguiente si no se indica otro.
 */
final readonly class ResultadoValidacion
{
    /** @param list<ErrorRegla> $errores */
    public function __construct(
        public array $errores,
        public ?Visita $visita,
        public ?Visita $anterior,
        public ?Visita $siguiente,
        public ?int $desplazamientoSiguiente,
        private bool $origenConocido,
        public ?int $desplazamientoMinimo = null,
        public ?int $desplazamientoSiguienteSugerido = null,
    ) {
    }

    public function esValida(): bool
    {
        return $this->errores === [];
    }

    /** @return list<string> Códigos de regla, en el orden de los errores. */
    public function reglas(): array
    {
        return array_map(fn (ErrorRegla $error) => $error->regla, $this->errores);
    }

    /** SEDE o VISITA; null si no se puede calcular porque la hora no es válida. */
    public function origenTipo(): ?string
    {
        if (!$this->origenConocido) {
            return null;
        }

        return $this->anterior === null ? Visita::ORIGEN_SEDE : Visita::ORIGEN_VISITA;
    }

    public function origenVisitaId(): ?int
    {
        return $this->anterior?->id;
    }
}
