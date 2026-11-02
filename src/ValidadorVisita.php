<?php

declare(strict_types=1);

namespace App;

/**
 * Reglas para programar una visita (sección 4 del análisis). Clase pura: no toca la base de datos.
 *
 * 1. Campos. Si hay errores de CAMPOS, se devuelven sin evaluar el resto.
 * 2. Jornada: empezar no antes de las 08:00, salir de la sede no antes de las 08:00 y acabar no después de las 17:00.
 * 3. Solape: contra todas las visitas activas del técnico ese día.
 * 4. Llegada: desde la visita anterior, con el desplazamiento de la nueva (mínimo 30 entre obras
 *    diferentes; 0 desde la sede o en la misma obra, ver Reglas::desplazamientoMinimo).
 * 5. Salida: hasta la visita siguiente, con su desplazamiento.
 *
 * Intervalos semiabiertos [inicio, fin). Si la nueva solapa con la anterior o la siguiente, no se
 * informa además de desplazamiento con esa misma visita.
 */
final class ValidadorVisita
{
    /**
     * @param array<string, mixed> $datos tecnico_id, obra_id, fecha, hora_inicio, duracion_min, desplazamiento_min
     *        y, opcional, desplazamiento_siguiente_min. El resto (por ejemplo, el origen) se ignora: se calcula aquí.
     * @param list<Visita> $visitasDelDia Visitas del técnico ese día, en cualquier estado.
     * @param array<int, string> $tecnicos Técnicos válidos, id => nombre.
     * @param array<int, string> $obras Obras válidas, id => nombre.
     */
    public function validar(array $datos, array $visitasDelDia, array $tecnicos, array $obras): ResultadoValidacion
    {
        $activas = $this->activasDelTecnico($visitasDelDia, $datos['tecnico_id'] ?? null, $datos['fecha'] ?? null);

        // El origen se calcula en cuanto la hora es válida, para que la previsualización pueda mostrarlo.
        $origenConocido = Horas::esValida($datos['hora_inicio'] ?? null);
        $anterior = null;
        $siguiente = null;
        if ($origenConocido) {
            [$anterior, $siguiente] = $this->vecinas($activas, Horas::aMinutos($datos['hora_inicio']));
        }

        // Mínimos de desplazamiento (R1 y R2): también se informan con errores, para la previsualización.
        $obraId = is_int($datos['obra_id'] ?? null) && array_key_exists($datos['obra_id'], $obras) ? $datos['obra_id'] : null;
        $minimoLlegada = self::minimoDeLlegada($anterior, $obraId);
        $minimoSalida = $siguiente === null || $obraId === null
            ? Reglas::DESPLAZAMIENTO_MINIMO
            : Reglas::desplazamientoMinimo($obraId, $siguiente->obraId);
        $minimoInformado = $origenConocido && ($anterior === null || $obraId !== null) ? $minimoLlegada : null;
        // Lo que tendrá la siguiente si no se indica otro valor: en la misma obra, 0; si no, el que ya
        // tenía, pero nunca menos del mínimo (pudo salir antes de la sede con menos).
        $sugeridoSiguiente = $siguiente === null || $obraId === null
            ? null
            : ($minimoSalida === 0 ? 0 : max($siguiente->desplazamiento, $minimoSalida));

        $errores = $this->validarCampos($datos, $tecnicos, $obras, $minimoLlegada, $minimoSalida);
        if ($errores !== []) {
            return new ResultadoValidacion($errores, null, $anterior, $siguiente, null, $origenConocido, $minimoInformado, $sugeridoSiguiente);
        }

        $visita = new Visita(
            null,
            $datos['tecnico_id'],
            $datos['obra_id'],
            $obras[$datos['obra_id']],
            $datos['fecha'],
            Horas::aMinutos($datos['hora_inicio']),
            $datos['duracion_min'],
            $datos['desplazamiento_min'],
            Visita::PROGRAMADA,
            $anterior?->id,
        );
        $desplazamientoSiguiente = $siguiente === null
            ? null
            : ($datos['desplazamiento_siguiente_min'] ?? $sugeridoSiguiente);

        $errores = $this->validarReglas($visita, $activas, $anterior, $siguiente, $desplazamientoSiguiente);

        return new ResultadoValidacion($errores, $visita, $anterior, $siguiente, $desplazamientoSiguiente, true, $minimoInformado, $sugeridoSiguiente);
    }

    /** Desde la sede no hay mínimo (R2); si la obra aún no es válida, se aplica el mínimo entre obras. */
    private static function minimoDeLlegada(?Visita $anterior, ?int $obraId): int
    {
        if ($anterior === null) {
            return 0;
        }

        return $obraId === null ? Reglas::DESPLAZAMIENTO_MINIMO : Reglas::desplazamientoMinimo($anterior->obraId, $obraId);
    }

    /**
     * @param array<string, mixed> $datos
     * @param array<int, string> $tecnicos
     * @param array<int, string> $obras
     * @return list<ErrorRegla>
     */
    private function validarCampos(array $datos, array $tecnicos, array $obras, int $minimoLlegada, int $minimoSalida): array
    {
        $errores = [];

        $tecnicoId = $datos['tecnico_id'] ?? null;
        if (!is_int($tecnicoId) || !array_key_exists($tecnicoId, $tecnicos)) {
            $errores[] = new ErrorRegla(ErrorRegla::CAMPOS, 'Elige un técnico válido', 'tecnico_id');
        }

        $obraId = $datos['obra_id'] ?? null;
        if (!is_int($obraId) || !array_key_exists($obraId, $obras)) {
            $errores[] = new ErrorRegla(ErrorRegla::CAMPOS, 'Elige una obra válida', 'obra_id');
        }

        if (!self::esFechaValida($datos['fecha'] ?? null)) {
            $errores[] = new ErrorRegla(ErrorRegla::CAMPOS, 'La fecha no es válida (formato AAAA-MM-DD)', 'fecha');
        }

        if (!Horas::esValida($datos['hora_inicio'] ?? null)) {
            $errores[] = new ErrorRegla(ErrorRegla::CAMPOS, 'La hora de inicio no es válida (formato HH:MM)', 'hora_inicio');
        }

        $duracion = $datos['duracion_min'] ?? null;
        if (!is_int($duracion) || $duracion < Reglas::DURACION_MINIMA || $duracion > Reglas::DURACION_MAXIMA) {
            $errores[] = new ErrorRegla(
                ErrorRegla::CAMPOS,
                sprintf('La duración debe estar entre %d y %d min', Reglas::DURACION_MINIMA, Reglas::DURACION_MAXIMA),
                'duracion_min'
            );
        }

        $error = $this->validarDesplazamiento($datos['desplazamiento_min'] ?? null, $minimoLlegada, 'desplazamiento_min');
        if ($error !== null) {
            $errores[] = $error;
        }

        // "Desplazamiento hasta la siguiente" es opcional; si llega, cumple el mismo criterio.
        if (($datos['desplazamiento_siguiente_min'] ?? null) !== null) {
            $error = $this->validarDesplazamiento($datos['desplazamiento_siguiente_min'], $minimoSalida, 'desplazamiento_siguiente_min');
            if ($error !== null) {
                $errores[] = $error;
            }
        }

        return $errores;
    }

    /**
     * Un valor vacío, que no sea entero o menor del mínimo se rechaza; nunca se corrige.
     * El mínimo es 30 entre obras diferentes y 0 desde la sede o en la misma obra.
     */
    private function validarDesplazamiento(mixed $valor, int $minimo, string $campo): ?ErrorRegla
    {
        $mensajeMinimo = $minimo > 0 ? "El desplazamiento mínimo es {$minimo} min" : null;
        if (!is_int($valor)) {
            return new ErrorRegla(ErrorRegla::CAMPOS, $mensajeMinimo ?? 'Indica el desplazamiento en minutos', $campo);
        }
        if ($valor < $minimo || $valor < 0) {
            return new ErrorRegla(ErrorRegla::CAMPOS, $mensajeMinimo ?? 'El desplazamiento no puede ser negativo', $campo);
        }
        if ($valor > Reglas::DESPLAZAMIENTO_MAXIMO) {
            return new ErrorRegla(ErrorRegla::CAMPOS, sprintf('El desplazamiento no puede superar %d min', Reglas::DESPLAZAMIENTO_MAXIMO), $campo);
        }

        return null;
    }

    /**
     * @param list<Visita> $activas
     * @return list<ErrorRegla>
     */
    private function validarReglas(Visita $nueva, array $activas, ?Visita $anterior, ?Visita $siguiente, ?int $desplazamientoSiguiente): array
    {
        $errores = [];
        $inicio = $nueva->inicio;
        $fin = $nueva->fin();

        // Jornada (regla c), incluida la salida desde la sede.
        if ($inicio < Reglas::JORNADA_INICIO) {
            $errores[] = new ErrorRegla(
                ErrorRegla::JORNADA,
                'La visita no puede empezar antes de las ' . Horas::aTexto(Reglas::JORNADA_INICIO)
            );
        } elseif ($anterior === null && $nueva->salida() < Reglas::JORNADA_INICIO) {
            $errores[] = new ErrorRegla(ErrorRegla::JORNADA, sprintf(
                'Con %d min desde la sede, la visita no puede empezar antes de las %s',
                $nueva->desplazamiento,
                Horas::aTexto(Reglas::JORNADA_INICIO + $nueva->desplazamiento)
            ));
        }
        if ($fin > Reglas::JORNADA_FIN) {
            $acabaria = $fin < 24 * 60 ? 'a las ' . Horas::aTexto($fin) : 'después de medianoche';
            $errores[] = new ErrorRegla(
                ErrorRegla::JORNADA,
                "La visita acabaría {$acabaria} y la jornada termina a las " . Horas::aTexto(Reglas::JORNADA_FIN)
            );
        }

        // 1. Solape (regla a): la nueva acaba antes de que empiece la otra o empieza después de que acabe.
        $solapadas = [];
        foreach ($activas as $otra) {
            if (!($fin <= $otra->inicio || $inicio >= $otra->fin())) {
                $solapadas[] = $otra;
                $errores[] = new ErrorRegla(ErrorRegla::SOLAPE, sprintf(
                    'Solapa con la visita de %s a %s en %s',
                    Horas::aTexto($otra->inicio),
                    Horas::aTexto($otra->fin()),
                    $otra->obraNombre
                ));
            }
        }

        // 2. Llegada (regla b): tiene que dar tiempo a llegar desde la anterior.
        if ($anterior !== null && !in_array($anterior, $solapadas, true)) {
            $comoPronto = $anterior->fin() + $nueva->desplazamiento;
            if ($inicio < $comoPronto) {
                $errores[] = new ErrorRegla(ErrorRegla::DESPLAZAMIENTO, sprintf(
                    'No da tiempo a llegar desde %s (acaba %s): con %d min de desplazamiento, como pronto a las %s',
                    $anterior->obraNombre,
                    Horas::aTexto($anterior->fin()),
                    $nueva->desplazamiento,
                    Horas::aTexto($comoPronto)
                ));
            }
        }

        // 3. Salida (regla b): después de la nueva tiene que dar tiempo a llegar a la siguiente.
        if ($siguiente !== null && $desplazamientoSiguiente !== null && !in_array($siguiente, $solapadas, true)) {
            $comoTarde = $siguiente->inicio - $desplazamientoSiguiente;
            if ($fin > $comoTarde) {
                $errores[] = new ErrorRegla(ErrorRegla::DESPLAZAMIENTO, sprintf(
                    'No da tiempo a llegar a la siguiente visita en %s a las %s: esta visita debería acabar como tarde a las %s',
                    $siguiente->obraNombre,
                    Horas::aTexto($siguiente->inicio),
                    Horas::aTexto($comoTarde)
                ));
            }
        }

        return $errores;
    }

    /**
     * Visitas activas (Programadas y Completadas) del técnico en esa fecha, ordenadas por hora.
     *
     * @param list<Visita> $visitas
     * @return list<Visita>
     */
    private function activasDelTecnico(array $visitas, mixed $tecnicoId, mixed $fecha): array
    {
        $activas = array_values(array_filter(
            $visitas,
            fn (Visita $v) => $v->estaActiva() && $v->tecnicoId === $tecnicoId && $v->fecha === $fecha
        ));
        usort($activas, fn (Visita $a, Visita $b) => $a->inicio <=> $b->inicio);

        return $activas;
    }

    /**
     * Anterior: la última que empieza antes de $inicio. Siguiente: la primera que empieza en $inicio o después.
     *
     * @param list<Visita> $activas Ordenadas por hora.
     * @return array{?Visita, ?Visita}
     */
    private function vecinas(array $activas, int $inicio): array
    {
        $anterior = null;
        foreach ($activas as $visita) {
            if ($visita->inicio >= $inicio) {
                return [$anterior, $visita];
            }
            $anterior = $visita;
        }

        return [$anterior, null];
    }

    /** Fecha real con formato AAAA-MM-DD. Pública para que el servicio no consulte la base con fechas imposibles. */
    public static function esFechaValida(mixed $fecha): bool
    {
        return is_string($fecha)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $fecha, $partes) === 1
            && checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1]);
    }
}
